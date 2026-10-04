// The phone event sheet's contents (0.5.2): read at arm's length, act with
// the thumb. The event's facts as rows with an icon each (when, where or the
// meeting to join, reminders, people, tags, description); "For me" pinned
// above four labelled actions; everything else under More. The sheet itself
// (height, handle, stepping, gestures) is EventPopover's; desktop keeps the
// popover's own body.

import { html, useState, useEffect, useRef } from '../../vendor/index.js';
import { set, state, toast, invalidateRecords } from './store.js';
import { api, refreshWindow } from './api.js';
import { CopyTo } from './CopyTo.js';
import { DeleteScope, ScopeChoice } from './DeleteScope.js';
import { relationshipOptions, REL_LABEL } from './Relationship.js';
import {
  deleteEvent, updateEvent, setRelationship, sendFeedback, enterReschedule, openDetail, openTripByEventId,
  googleBacked, GOOGLE_NO_UNDO, toggleCalendarVisible, rsvpEvent, goBackTo, editFromView,
} from './actions.js';
import { describeRrule, MiniMap, useEventGeo, linkify } from './eventparts.js';
import { EventPluginData } from './EventPluginData.js';
import { Icon, ThumbIcon, PinIcon } from '../ui/icons.js';
import { DurationSuffix } from '../ui/EventChip.js';
import { fmtRange, zoneNote, fmtDateFull, fmtTime, parseISO, startMs } from '../lib/dates.js';
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
// The site a booking email came from ("noreply@order.eventbrite.com" →
// eventbrite.com); none for mail forwarded from a personal mailbox, which
// says nothing about the booking.
const WEBMAIL = /^(gmail|googlemail|outlook|hotmail|live|icloud|me|yahoo|proton|protonmail|fastmail|aol)\./;
function senderSite(addr) {
  const domain = String(addr || '').split('@')[1];
  if (!domain) return null;
  const parts = domain.toLowerCase().split('.');
  const keep = parts.length > 2 && /^(co|com|org|net|ac|gov|edu)$/.test(parts[parts.length - 2]) ? 3 : 2; // example.co.uk
  const site = parts.slice(-keep).join('.');
  return WEBMAIL.test(site) ? null : site;
}

// The same event on other calendars (#9), shown once in the grid: where
// else it is, a way to open that copy, and "Not the same" for a wrong match.
function AlsoOn({ occ }) {
  const [busy, setBusy] = useState(false);
  // The whole group, not only direct links: A and C may each be linked to B.
  const seen = new Map([[occ.eventId, null]]);
  const queue = [...occ.dupes];
  while (queue.length) {
    const d = queue.shift();
    if (seen.has(d.eventId)) continue;
    seen.set(d.eventId, d);
    for (const x of state.occ.values()) {
      if (x.eventId === d.eventId && x.dupes) queue.push(...x.dupes);
    }
  }
  const copies = [...seen.values()].filter(Boolean)
    .map((d) => ({ ...d, cal: state.calendars.find((c) => c.id === d.calendarId) }))
    .filter((d) => d.cal);
  if (!copies.length) return null;
  const at = startMs(occ);
  const copyOcc = (d) => {
    for (const x of state.occ.values()) {
      if (x.eventId === d.eventId && (!occ.recurring || startMs(x) === at)) return x;
    }
    return null;
  };
  const notSame = async (d) => {
    setBusy(true);
    try {
      await api('/duplicates/' + d.pairId, { method: 'POST', body: { status: 'dismissed' } });
      toast('Shown as separate events again');
      invalidateRecords(occ.eventId);
      refreshWindow();
    } catch (e) {
      toast("Couldn't change that: " + e.message, { error: true });
    }
    setBusy(false);
  };
  return html`<div class="bc-es-alsoon">
    <${Icon} name="stack" size=${15} />
    <span>Also on</span>
    ${copies.map((d) => {
      const other = copyOcc(d);
      return html`<span class="bc-es-alsoon-item" key=${d.pairId}>
        ${other
          ? html`<button type="button" class="bc-es-alsoon-cal" title="Open this copy" onClick=${() => openDetail(other.instanceId)}><i style=${'background:' + (d.cal.color || 'var(--border-strong)')}></i>${d.cal.name}</button>`
          : html`<span class="bc-es-alsoon-cal"><i style=${'background:' + (d.cal.color || 'var(--border-strong)')}></i>${d.cal.name}</span>`}
        <button type="button" class="bc-es-alsoon-split" disabled=${busy} title="These are different events: show both" onClick=${() => notSame(d)}>Not the same</button>
      </span>`;
    })}
  </div>`;
}

const ANSWERS = [['accepted', 'ACCEPTED', 'Accept'], ['tentative', 'TENTATIVE', 'Maybe'], ['declined', 'DECLINED', 'Decline']];
const ANSWERED = { ACCEPTED: 'Accepted', TENTATIVE: 'Maybe', DECLINED: 'Declined' };

// The invite block (#70). A booking read from mail (a reservation, ticket or
// confirmation) has nobody to answer, so it only says where it came from. An
// invitation shows Accept / Maybe / Decline while a reply can go; when it
// can't, it says why instead. A reply that didn't go keeps the answer and
// says so, with Retry, and the answer can still be changed.
function InviteBlock({ occ }) {
  const inv = occ.invite;
  const [changing, setChanging] = useState(false);
  const [busy, setBusy] = useState(false);
  if (inv.kind === 'booking') {
    const via = occ.url ? hostOf(occ.url) : senderSite(inv.from || (inv.organizer && inv.organizer.email));
    return html`<div class="bc-es-row">
      <${Icon} name="mail" size=${20} />
      <div class="bc-es-rt">Booking${via ? ' via ' + via : ''}<small>Read from an email; nothing to answer</small></div>
    </div>`;
  }
  const answer = async (a) => {
    setBusy(true);
    const r = await rsvpEvent(occ, a);
    setBusy(false);
    if (r) setChanging(false);
  };
  const from = inv.organizer && inv.organizer.email ? ' from ' + (inv.organizer.name || inv.organizer.email) : '';
  const count = inv.attendees && inv.attendees.length > 1 ? inv.attendees.length + ' invited' : null;
  const where = inv.via === 'google' ? 'In Google Calendar' : null;
  const failed = inv.reply && !inv.reply.sent && inv.reply.partstat === inv.myPartstat ? inv.reply : null;
  return html`<div class="bc-es-invite">
    <div class="bc-es-row">
      <${Icon} name="mail" size=${20} />
      <div class="bc-es-rt">Invitation${from}${(count || where) && html`<small>${[count, where].filter(Boolean).join(' · ')}</small>`}</div>
    </div>
    ${!inv.canReply && html`<p class="bc-es-invite-note">${inv.myPartstat && ANSWERED[inv.myPartstat] ? ANSWERED[inv.myPartstat] + ' here. ' : ''}Can't be answered from Better-Cal: ${inv.why}</p>`}
    ${inv.canReply && failed && !changing && html`<div class="bc-es-invite-note is-failed">
      <span>${ANSWERED[failed.partstat]} here; the reply couldn't be sent: ${failed.message || 'unknown error'}</span>
      <span class="bc-es-invite-acts">
        <button type="button" class="bc-btn" disabled=${busy} onClick=${() => answer(ANSWERS.find((x) => x[1] === failed.partstat)[0])}>Retry</button>
        <button type="button" class="bc-btn" onClick=${() => setChanging(true)}>Change answer</button>
      </span>
    </div>`}
    ${inv.canReply && (!failed || changing) && html`<span class="bc-es-seg" role="group" aria-label="Reply to the invitation">
      ${ANSWERS.map(([a, ps, label]) => html`<button
        key=${a} type="button" class=${'bc-es-segbtn' + (inv.myPartstat === ps ? ' is-on' : '')} disabled=${busy}
        aria-pressed=${inv.myPartstat === ps} onClick=${() => answer(a)}
      >${label}</button>`)}
    </span>`}
  </div>`;
}

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

// The event's own web address, by the site it belongs to: "partiful.com".
function sourceHost(url) {
  try { return new URL(url).hostname.replace(/^www\./, ''); } catch { return ''; }
}

// More (0.5.8). Three groups, each saying what it acts on, because "the
// calendar", "the event" and "the event's page" will mean different things
// once calendars can be shared: this event (Copy to), the calendar it is on
// (named, with its colour), and the site it came from (named by its address,
// so nobody mistakes it for a page of this app). Delete last.
function MoreMenu({ occ, cal, isFeed, onClose, onDelete, onCopy, phone = false, onMove = null }) {
  const ref = useRef(null);
  useEffect(() => onOutsidePress(insideAny(ref), onClose), []); // eslint-disable-line
  const item = (icon, label, fn, cls = '', note = null) => html`<button type="button" role="menuitem" class=${'bc-es-mi ' + cls} onClick=${() => { onClose(); fn(); }}>
    ${typeof icon === 'string' ? html`<${Icon} name=${icon} size=${18} />` : icon}<span>${label}</span>${note && html`<small>${note}</small>`}
  </button>`;
  const context = cal && cal.role === 'context';
  const head = (content) => html`<div class="bc-es-mh" role="presentation">${content}</div>`;
  const calName = (cal && cal.name) || 'Calendar';
  const host = occ.url ? sourceHost(occ.url) : '';
  return html`<div class=${'bc-es-menu' + (phone ? ' is-phone' : '')} role="menu" ref=${ref}>
    ${phone && !isFeed && onMove && item('reschedule', 'Move to another time', onMove)}
    ${phone && isFeed && !context && item(html`<${ThumbIcon} dir="up" size=${18} />`, 'More like this', () => sendFeedback(occ, 'up'))}
    ${phone && isFeed && !context && item(html`<${ThumbIcon} dir="down" size=${18} />`, 'Less like this', () => sendFeedback(occ, 'down'))}
    ${item('copy', 'Copy to another calendar…', onCopy)}
    ${cal && html`<hr />
      ${head(html`<i style=${'background:' + (cal.color || 'var(--border-strong)')}></i>Calendar: ${calName}`)}
      ${item('settings', 'Calendar settings', () => set({ popover: null, manageCal: cal.id }))}
      ${cal.visible && item('eyeOff', 'Hide this calendar', () => { set({ popover: null }); toggleCalendarVisible(cal); toast(calName + ' hidden. Show it again from the sidebar.', { duration: 4000 }); })}`}
    ${occ.url && html`<hr />
      ${head(html`Source: ${host || 'web page'}`)}
      ${item('arrowUpRight', host ? 'Open on ' + host : 'Open the source page', () => window.open(occ.url, '_blank', 'noopener'), '', 'new tab')}
      ${item('link', host ? 'Copy the ' + host + ' link' : 'Copy the source link', () => copyText(occ.url, host ? host + ' link' : 'Source link'))}`}
    ${!isFeed && html`<hr />`}
    ${!isFeed && item('trash', 'Delete…', onDelete, 'is-danger', occ.recurring ? 'asks which ones' : null)}
  </div>`;
}

export function EventSheetBody({ occ, cal, isFeed, full, panel = false, onFull, bodyRef, copyOpen, setCopyOpen, timeScope, setTimeScope, deletePrompt, attendPrompt, s, e, backTo = null }) {
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

  const context = cal && cal.role === 'context';
  const openEdit = () => editFromView(occ);
  const openMove = () => { close(); enterReschedule(occ.instanceId); };
  const moreMenu = moreOpen && html`<${MoreMenu} occ=${occ} cal=${cal} isFeed=${isFeed} phone=${!panel} onMove=${openMove} onClose=${() => setMoreOpen(false)} onDelete=${onDelete} onCopy=${() => setCopyOpen(true)} />`;

  // The desktop panel pins them in one toolbar row at the top instead (0.5.7).
  // The row is the same height for every event, For me or not, so stepping
  // through a day never moves a button or the content under it. Edit and Move
  // keep their words; the rest are icons with tooltips so one row holds all.
  // Copy to lives in More: it is rare, and a bare copy icon read as Duplicate.
  const tool = (icon, label, onClick, extra = {}) => html`<button
    type="button" class=${'bc-es-tool' + (extra.word ? '' : ' is-icon') + (extra.on ? ' is-on' : '')}
    aria-label=${label} title=${extra.word ? undefined : label} aria-expanded=${extra.expanded} aria-haspopup=${extra.popup}
    onClick=${onClick}
  >${icon}${extra.word ? label : ''}</button>`;
  // The phone (0.6.1): no band of big buttons. The event's details come
  // first; Edit and More are two small icons beside the title, For me a small
  // switch on the calendar line (it is part of what the event is to you, not
  // a command), and everything rarer lives in More, which opens from the
  // bottom edge under the thumb.
  const titleActs = !panel && html`<span class="bc-es-titleacts" role="toolbar" aria-label="Event actions">
      ${!isFeed && tool(html`<${Icon} name="pencil" size=${18} />`, 'Edit', openEdit)}
      ${tool(html`<${Icon} name="more" size=${19} />`, 'More', () => setMoreOpen(!moreOpen), { on: moreOpen, expanded: moreOpen, popup: 'menu' })}
    </span>`;

  const tools = html`<div class="bc-es-tools" role="toolbar" aria-label="Event actions">
      <span class="bc-es-tools-acts">
        ${!isFeed && tool(html`<${Icon} name="pencil" size=${15} />`, 'Edit', openEdit, { word: true })}
        ${!isFeed && tool(html`<${Icon} name="reschedule" size=${15} />`, 'Move', openMove, { word: true })}
        ${isFeed && !context && tool(html`<${ThumbIcon} dir="up" size=${16} />`, 'More like this', () => sendFeedback(occ, 'up'))}
        ${isFeed && !context && tool(html`<${ThumbIcon} dir="down" size=${16} />`, 'Less like this', () => sendFeedback(occ, 'down'))}
        ${tool(html`<${Icon} name="more" size=${16} />`, 'More', () => setMoreOpen(!moreOpen), { on: moreOpen, expanded: moreOpen, popup: 'menu' })}
      </span>
      ${!context && html`<${ForMe} occ=${occ} cal=${cal} onHidden=${close} />`}
      ${moreMenu}
    </div>`;

  // Which occurrences a For me change is for, on a repeating event. On the
  // phone it opens right under the switch that asked (0.6.2); at the top of
  // the sheet it was out of view whenever the sheet had scrolled, and the
  // switch seemed to do nothing.
  const attendScope = attendPrompt && attendPrompt.instanceId === occ.instanceId && html`<${ScopeChoice} occ=${occ} verb=${attendPrompt.label + ' for'} compact note=${cal && cal.role === 'mine' && googleBacked(occ) ? GOOGLE_NO_UNDO : null}
        onPick=${async (scope) => { const v = attendPrompt.relationship; set({ attendPrompt: null }); const r = await setRelationship(occ, v, scope); if (r === 'hidden') close(); }}
        onCancel=${() => set({ attendPrompt: null })} />`;

  return html`
    <div class="bc-pop-body bc-es-body" ref=${bodyRef}>
      ${panel && tools}
      ${copyOpen && html`<${CopyTo} occ=${occ} compact onDone=${close} />`}
      ${deletePrompt === occ.instanceId && html`<${DeleteScope} occ=${occ} compact onDone=${() => set({ deletePrompt: null, popover: null })} onCancel=${() => set({ deletePrompt: null })} />`}
      ${timeScope && html`<${ScopeChoice} occ=${occ} verb="New time for" compact note=${googleBacked(occ) ? GOOGLE_NO_UNDO : null} onPick=${(scope) => { const f = timeScope; setTimeScope(null); updateEvent(occ, f, scope); }} onCancel=${() => setTimeScope(null)} />`}
      ${panel && attendScope}

      ${backTo && html`<button type="button" class="bc-es-backto" onClick=${() => goBackTo(backTo)}>
        <${Icon} name="chevronLeft" size=${15} /><${Icon} name=${backTo.search ? 'search' : 'trip'} size=${14} />${backTo.title}
      </button>`}
      <div class="bc-es-head">
        <div class="bc-es-titlerow">
          <h2 class=${'bc-es-title' + (occ.status === 'cancelled' ? ' is-cancelled' : '')} onClick=${onFull} title="Show all details">
            ${occ.title || '(untitled)'}${occ.isNew ? html` <span class="bc-new-pill">new</span>` : ''}
          </h2>
          ${titleActs}
        </div>
        <div class="bc-es-calrow">
          <span class="bc-es-cal"><i style=${'background:' + color}></i>${(cal && cal.name) || 'Calendar'}${suggested ? ' · suggested' : ''}${occ.status === 'cancelled' ? ' · cancelled' : ''}</span>
          ${!panel && !context && html`<${ForMe} occ=${occ} cal=${cal} onHidden=${close} />`}
        </div>
        ${!panel && attendScope}
        ${occ.dupes && occ.dupes.length > 0 && html`<${AlsoOn} occ=${occ} />`}
        ${occ.containers && occ.containers.length > 0 && html`<button
          type="button" class="bc-es-partof" onClick=${() => { close(); openTripByEventId(occ.containers[0].eventId); }}
        ><${Icon} name="trip" size=${15} />Part of ${occ.containers[0].title}</button>`}
      </div>

      <div class="bc-es-row">
        <${Icon} name="clock" size=${20} />
        <div class="bc-es-rt">
          <span class="bc-date-duration">${fmtRange(s, e, occ.allDay)} <${DurationSuffix} occ=${occ} expanded /></span>
          ${(occ.recurring || zoneNote(occ)) && html`<small>${[occ.recurring ? describeRrule(occ.rrule) : null, zoneNote(occ)].filter(Boolean).join(' · ')}</small>`}
        </div>
      </div>

      ${/* An invitation's reply is the thing to do: right under when. */ ''}
      ${occ.invite && html`<${InviteBlock} occ=${occ} />`}
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
        <${PinIcon} size=${20} /><div class=${'bc-es-rt bc-es-addr' + (full ? '' : ' is-clamped')} title=${loc}>${loc}</div>
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

    ${!panel && moreMenu}`;
}
