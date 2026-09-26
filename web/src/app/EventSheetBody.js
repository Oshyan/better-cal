// The phone event sheet's contents (0.5.2): read at arm's length, act with
// the thumb. The event's facts as rows with an icon each (when, where or the
// meeting to join, reminders, people, tags, description); "For me" pinned
// above four labelled actions; everything else under More. The sheet itself
// (height, handle, stepping, gestures) is EventPopover's; desktop keeps the
// popover's own body.

import { html, useState, useEffect, useRef } from '../../vendor/index.js';
import { set, state, toast } from './store.js';
import { CopyTo } from './CopyTo.js';
import { DeleteScope, ScopeChoice } from './DeleteScope.js';
import { relationshipOptions, REL_LABEL } from './Relationship.js';
import {
  deleteEvent, updateEvent, setRelationship, sendFeedback, enterReschedule, openDetail, openTripByEventId,
  googleBacked, GOOGLE_NO_UNDO, toggleCalendarVisible, rsvpEvent,
} from './actions.js';
import { describeRrule, MiniMap, useEventGeo, linkify } from './EventDetail.js';
import { EventPluginData } from './EventPluginData.js';
import { Icon, ThumbIcon, PinIcon } from '../ui/icons.js';
import { DurationSuffix } from '../ui/EventChip.js';
import { fmtRange, zoneNote, fmtDateFull, fmtTime, parseISO } from '../lib/dates.js';
import { fmtReminder } from '../lib/reminders.js';
import { stripToText, hasHtml, sanitizeHtml } from '../lib/richtext.js';
import { gmapsUrl, isPendingLocation } from '../lib/maps.js';
import { onOutsidePress, insideAny } from '../ui/outside.js';

// Video meetings: the link is a Join button, not an address.
const MEETINGS = [
  [/https?:\/\/[\w.-]*zoom\.us\/[^\s<>"')]+/i, 'Zoom'],
  [/https?:\/\/meet\.google\.com\/[^\s<>"')]+/i, 'Google Meet'],
  [/https?:\/\/teams\.(?:microsoft|live)\.com\/[^\s<>"')]+/i, 'Teams'],
  [/https?:\/\/[\w.-]*webex\.com\/[^\s<>"')]+/i, 'Webex'],
];

/** The first video-meeting link in the location, link or description: {url, name}. */
export function meetingLink(occ) {
  for (const text of [occ.location, occ.url, occ.description && stripToText(occ.description)]) {
    if (!text) continue;
    for (const [re, name] of MEETINGS) {
      const m = String(text).match(re);
      if (m) return { url: m[0].replace(/[.,;:]+$/, ''), name };
    }
  }
  return null;
}

const isUrl = (s) => /^(https?:\/\/|www\.)\S+$/i.test((s || '').trim());
const hostOf = (u) => { try { return new URL(/^www\./i.test(u) ? 'https://' + u : u).hostname.replace(/^www\./, ''); } catch { return u; } };

function copyText(text, what) {
  const done = () => toast(what + ' copied', { duration: 2000 });
  if (navigator.clipboard && navigator.clipboard.writeText) {
    navigator.clipboard.writeText(text).then(done, () => toast('Could not copy', { error: true }));
  } else toast('Could not copy', { error: true });
}

// "For me" as buttons. Your own calendars: Planned or Maybe. Suggestions:
// Planned, Maybe or Hide, none lit while it is only available; tapping the
// lit one puts it back to available. A series asks which occurrences.
function ForMe({ occ, cal, onHidden }) {
  const role = (cal && cal.role) || (occ.source === 'feed' ? 'opportunities' : 'mine');
  const allowed = relationshipOptions(role);
  const options = ['planned', 'maybe', 'hidden'].filter((v) => allowed.includes(v)); // commitment first, Hide last
  if (options.length === 0) return null;
  const current = occ.attendance === 'hidden' ? 'hidden' : (occ.relationship || options[0]);
  const choose = async (value) => {
    const next = value === current && role !== 'mine' ? 'available' : value;
    if (next === current) return;
    if (occ.recurring) { set({ attendPrompt: { instanceId: occ.instanceId, relationship: next, label: REL_LABEL[next] } }); return; }
    const r = await setRelationship(occ, next);
    if (r === 'hidden' && onHidden) onHidden();
  };
  const label = (v) => (v === 'hidden' ? 'Hide' : REL_LABEL[v]);
  const icon = { planned: 'star', hidden: 'eyeOff' };
  return html`<div class="bc-es-forme" title="What this event is to you. A private note: nobody is notified.">
    <span class="bc-es-forme-label">For me</span>
    <span class="bc-es-seg" role="group" aria-label="What this event is to you">
      ${options.map((v) => html`<button
        key=${v} type="button" class=${'bc-es-segbtn' + (current === v ? ' is-on' : '')}
        aria-pressed=${current === v} onClick=${() => choose(v)}
      >${icon[v] && html`<${Icon} name=${icon[v]} size=${16} />`}${label(v)}</button>`)}
    </span>
  </div>`;
}

function MoreMenu({ occ, cal, isFeed, onClose, onDelete }) {
  const ref = useRef(null);
  useEffect(() => onOutsidePress(insideAny(ref), onClose), []); // eslint-disable-line
  const item = (icon, label, fn, cls = '', note = null) => html`<button type="button" role="menuitem" class=${'bc-es-mi ' + cls} onClick=${() => { onClose(); fn(); }}>
    <${Icon} name=${icon} size=${18} /><span>${label}</span>${note && html`<small>${note}</small>`}
  </button>`;
  const calName = (cal && cal.name) || 'Calendar';
  return html`<div class="bc-es-menu" role="menu" ref=${ref}>
    ${cal && item('calendar', 'Manage “' + calName + '”', () => set({ popover: null, manageCal: cal.id }), '', 'settings')}
    ${cal && cal.visible && item('eyeOff', 'Hide this calendar', () => { set({ popover: null }); toggleCalendarVisible(cal); toast(calName + ' hidden. Show it again from the sidebar.', { duration: 4000 }); })}
    <hr />
    ${occ.url && item('arrowUpRight', 'Open the event’s page', () => window.open(occ.url, '_blank', 'noopener'))}
    ${occ.url && item('link', 'Copy link to event', () => copyText(occ.url, 'Link'))}
    ${!isFeed && html`<hr />`}
    ${!isFeed && item('trash', 'Delete…', onDelete, 'is-danger', occ.recurring ? 'asks which ones' : null)}
  </div>`;
}

export function EventSheetBody({ occ, cal, isFeed, full, panel = false, onFull, bodyRef, copyOpen, setCopyOpen, timeScope, setTimeScope, deletePrompt, attendPrompt, s, e }) {
  const [moreOpen, setMoreOpen] = useState(false);
  useEffect(() => { setMoreOpen(false); }, [occ.instanceId]);
  const close = () => set({ popover: null });
  const color = (cal && cal.color) || '#888';
  const meet = meetingLink(occ);
  const loc = (occ.location || '').trim();
  const locIsMeeting = meet && loc && loc.includes(meet.url.slice(0, 24));
  const role = (cal && cal.role) || (isFeed ? 'opportunities' : 'mine');
  const suggested = role === 'opportunities';
  const onDelete = () => (occ.recurring || googleBacked(occ) ? set({ deletePrompt: occ.instanceId }) : (close(), deleteEvent(occ)));
  // Pulled up (0.5.3): the map, invitation replies, plugin data and where the
  // event came from join the sheet. The map's address lookup waits until then.
  const geo = useEventGeo(occ, full);
  const place = loc && !locIsMeeting && !isPendingLocation(loc) && !isUrl(loc);
  const mapLat = geo && geo.status === 'ok' ? geo.lat : null;
  const mapLng = geo && geo.status === 'ok' ? geo.lng : null;

  const desc = occ.description && (() => {
    const rich = hasHtml(occ.description);
    const text = rich ? '' : stripToText(occ.description);
    if (!rich && !text) return null;
    const long = rich ? true : text.length > 140;
    return html`<div class=${'bc-es-desc' + (occ.full ? ' bc-late' : '')}>
      ${rich
        ? html`<div class="bc-es-desctext bc-rich" dangerouslySetInnerHTML=${{ __html: sanitizeHtml(occ.description) }}></div>`
        : html`<div class="bc-es-desctext">${linkify(text)}</div>`}
      ${long && !full && html`<button type="button" class="bc-es-more" onClick=${onFull}>More</button>`}
    </div>`;
  })();

  // The phone keeps For me and the actions at the bottom, under the thumb;
  // the desktop panel puts them under the title, a short reach for the mouse.
  const foot = html`<div class=${'bc-es-foot' + (panel ? ' is-inline' : '')}>
      ${!(cal && cal.role === 'context') && html`<${ForMe} occ=${occ} cal=${cal} onHidden=${close} />`}
      <div class="bc-es-bar" role="toolbar" aria-label="Event actions">
        ${!isFeed && html`<button type="button" class="bc-es-act" onClick=${() => set({ popover: null, editor: { mode: 'edit', occ } })}><${Icon} name="pencil" size=${20} />Edit</button>`}
        ${!isFeed && html`<button type="button" class="bc-es-act" onClick=${() => { close(); enterReschedule(occ.instanceId); }}><${Icon} name="reschedule" size=${20} />Move</button>`}
        ${isFeed && !(cal && cal.role === 'context') && html`<button type="button" class="bc-es-act" onClick=${() => sendFeedback(occ, 'up')}><${ThumbIcon} dir="up" size=${20} />More like</button>`}
        ${isFeed && !(cal && cal.role === 'context') && html`<button type="button" class="bc-es-act" onClick=${() => sendFeedback(occ, 'down')}><${ThumbIcon} dir="down" size=${20} />Less like</button>`}
        <button type="button" class=${'bc-es-act' + (copyOpen ? ' is-on' : '')} aria-expanded=${copyOpen} onClick=${() => setCopyOpen(!copyOpen)}><${Icon} name="copy" size=${20} />Copy to</button>
        <button type="button" class=${'bc-es-act' + (moreOpen ? ' is-on' : '')} aria-haspopup="menu" aria-expanded=${moreOpen} onClick=${() => setMoreOpen(!moreOpen)}><${Icon} name="more" size=${20} />More</button>
      </div>
      ${moreOpen && html`<${MoreMenu} occ=${occ} cal=${cal} isFeed=${isFeed} onClose=${() => setMoreOpen(false)} onDelete=${onDelete} />`}
    </div>`;

  return html`
    <div class="bc-pop-body bc-es-body" ref=${bodyRef}>
      ${copyOpen && html`<${CopyTo} occ=${occ} compact onDone=${close} />`}
      ${deletePrompt === occ.instanceId && html`<${DeleteScope} occ=${occ} compact onDone=${() => set({ deletePrompt: null, popover: null })} onCancel=${() => set({ deletePrompt: null })} />`}
      ${timeScope && html`<${ScopeChoice} occ=${occ} verb="New time for" compact note=${googleBacked(occ) ? GOOGLE_NO_UNDO : null} onPick=${(scope) => { const f = timeScope; setTimeScope(null); updateEvent(occ, f, scope); }} onCancel=${() => setTimeScope(null)} />`}
      ${attendPrompt && attendPrompt.instanceId === occ.instanceId && html`<${ScopeChoice} occ=${occ} verb=${attendPrompt.label + ' for'} compact note=${cal && cal.role === 'mine' && googleBacked(occ) ? GOOGLE_NO_UNDO : null}
        onPick=${async (scope) => { const v = attendPrompt.relationship; set({ attendPrompt: null }); const r = await setRelationship(occ, v, scope); if (r === 'hidden') close(); }}
        onCancel=${() => set({ attendPrompt: null })} />`}

      <div class="bc-es-head">
        <h2 class=${'bc-es-title' + (occ.status === 'cancelled' ? ' is-cancelled' : '')} onClick=${onFull} title="Show all details">
          ${occ.title || '(untitled)'}${occ.isNew ? html` <span class="bc-new-pill">new</span>` : ''}
        </h2>
        <span class="bc-es-cal"><i style=${'background:' + color}></i>${(cal && cal.name) || 'Calendar'}${suggested ? ' · suggested' : ''}${occ.status === 'cancelled' ? ' · cancelled' : ''}</span>
        ${occ.containers && occ.containers.length > 0 && html`<button
          type="button" class="bc-es-partof" onClick=${() => { close(); openTripByEventId(occ.containers[0].eventId); }}
        ><${Icon} name="trip" size=${15} />Part of ${occ.containers[0].title}</button>`}
      </div>

      ${panel && foot}

      <div class="bc-es-row">
        <${Icon} name="clock" size=${20} />
        <div class="bc-es-rt">
          <span class="bc-date-duration">${fmtRange(s, e, occ.allDay)} <${DurationSuffix} occ=${occ} expanded /></span>
          ${(occ.recurring || zoneNote(occ)) && html`<small>${[occ.recurring ? describeRrule(occ.rrule) : null, zoneNote(occ)].filter(Boolean).join(' · ')}</small>`}
        </div>
      </div>

      ${/* An invitation's reply is the thing to do: right under when. */ ''}
      ${occ.invite && html`<div class="bc-es-invite">
        <div class="bc-es-row">
          <${Icon} name="mail" size=${20} />
          <div class="bc-es-rt">Invitation${occ.invite.organizer && occ.invite.organizer.email ? ' from ' + (occ.invite.organizer.name || occ.invite.organizer.email) : ''}${occ.invite.attendees && occ.invite.attendees.length > 1 ? html`<small>${occ.invite.attendees.length} invited</small>` : ''}</div>
        </div>
        <span class="bc-es-seg" role="group" aria-label="Reply to the invitation">
          ${[['accepted', 'ACCEPTED', 'Accept'], ['tentative', 'TENTATIVE', 'Maybe'], ['declined', 'DECLINED', 'Decline']].map(([answer, ps, label]) => html`<button
            key=${answer} type="button" class=${'bc-es-segbtn' + (occ.invite.myPartstat === ps ? ' is-on' : '')}
            aria-pressed=${occ.invite.myPartstat === ps} onClick=${() => rsvpEvent(occ, answer)}
          >${label}</button>`)}
        </span>
      </div>`}
      ${meet && html`<div class="bc-es-join">
        <a class="bc-es-joinbtn" href=${meet.url} target="_blank" rel="noopener noreferrer"><${Icon} name="video" size=${20} />Join ${meet.name}</a>
        <button type="button" class="bc-es-sqbtn" aria-label="Copy meeting link" title="Copy meeting link" onClick=${() => copyText(meet.url, 'Meeting link')}><${Icon} name="copy" size=${20} /></button>
      </div>`}

      ${loc && !locIsMeeting && isPendingLocation(loc) && html`<div class="bc-es-row is-pending" title=${loc}>
        <${Icon} name="lock" size=${20} /><div class="bc-es-rt">Location after RSVP</div>
      </div>`}
      ${loc && !locIsMeeting && !isPendingLocation(loc) && isUrl(loc) && html`<div class="bc-es-row">
        <${Icon} name="link" size=${20} /><div class="bc-es-rt">${hostOf(loc)}</div>
        <a class="bc-es-rowbtn" href=${/^www\./i.test(loc) ? 'https://' + loc : loc} target="_blank" rel="noopener noreferrer">Open <${Icon} name="arrowUpRight" size=${14} /></a>
      </div>`}
      ${place && html`<div class="bc-es-row">
        <${PinIcon} size=${20} /><div class="bc-es-rt">${loc}</div>
        <a class="bc-es-rowbtn" href=${gmapsUrl(loc, mapLat != null ? mapLat : occ.locationLat, mapLng != null ? mapLng : occ.locationLng)} target="_blank" rel="noopener noreferrer">Directions <${Icon} name="arrowUpRight" size=${14} /></a>
      </div>`}
      ${place && full && mapLat != null && html`<div class="bc-es-map"><${MiniMap} lat=${mapLat} lng=${mapLng} location=${loc} /></div>`}
      ${place && full && geo && geo.status === 'loading' && html`<div class="bc-es-map is-loading" aria-hidden="true"></div>`}
      ${place && full && geo && geo.status === 'none' && html`<div class="bc-es-note">Couldn\u2019t place this address on a map. A street address, or \u201cplace, city\u201d, will fix it.</div>`}

      ${occ.reminders && occ.reminders.length > 0 && html`<div class=${'bc-es-row' + (occ.full ? ' bc-late' : '')}>
        <${Icon} name="bell" size=${20} /><div class="bc-es-rt">${occ.reminders.map(fmtReminder).join(', ')}</div>
      </div>`}
      ${occ.people && occ.people.length > 0 && html`<div class="bc-es-row">
        <${Icon} name="people" size=${20} />
        <div class="bc-es-rt">${occ.people.map((n, i) => html`<span key=${n}>${i > 0 && ', '}<button
          type="button" class="bc-person-link" onClick=${() => set({ popover: null, route: 'people', peopleFocus: n })}
        >${n}</button></span>`)}</div>
      </div>`}
      ${occ.tags && occ.tags.length > 0 && html`<div class="bc-es-tags">${occ.tags.map((t) => '#' + t).join(' ')}</div>`}
      ${desc}
      ${occ.url && !(meet && occ.url.includes(meet.url.slice(0, 24))) && html`<div class="bc-es-row">
        <${Icon} name="link" size=${20} /><div class="bc-es-rt">${hostOf(occ.url)}</div>
        <a class="bc-es-rowbtn" href=${occ.url} target="_blank" rel="noopener noreferrer">Open <${Icon} name="arrowUpRight" size=${14} /></a>
      </div>`}
      ${full && html`<${EventPluginData} eventId=${occ.eventId} readOnly=${isFeed} />`}
      ${full && isFeed && cal && html`<div class="bc-es-row">
        <${Icon} name="calendar" size=${20} />
        <div class="bc-es-rt">${cal.name}<small>${cal.sourceUrl ? 'Subscribed feed' : 'Read-only calendar'}</small></div>
        ${cal.sourceUrl && html`<a class="bc-es-rowbtn" href=${cal.sourceUrl} target="_blank" rel="noopener noreferrer">Source <${Icon} name="arrowUpRight" size=${14} /></a>`}
      </div>`}
      ${full && occ.createdAt && html`<div class="bc-es-meta">
        Added ${fmtDateFull(parseISO(occ.createdAt))} ${fmtTime(parseISO(occ.createdAt))}${occ.updatedAt && occ.updatedAt !== occ.createdAt ? ' \u00b7 updated ' + fmtDateFull(parseISO(occ.updatedAt)) + ' ' + fmtTime(parseISO(occ.updatedAt)) : ''}
      </div>`}
    </div>

    ${!panel && foot}`;
}
