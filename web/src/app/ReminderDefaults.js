// Default-reminder controls, one for timed events and one for all-day
// events: a preset select plus Custom (number + unit). Shared by the global
// defaults in Settings, Notifications and each calendar's own defaults in
// its settings panel. Each edits one entry and reports the whole list:
// [] for None, [{minutes}] for timed, [{daysBefore, time}] for all-day.

import { html, useState } from '../../vendor/index.js';
import {
  TIMED_CHOICES, ALLDAY_DAYS_CHOICES, REMINDER_UNITS, fmtOffsetMinutes,
  toMinutes, fromMinutes,
} from '../lib/reminders.js';

export function TimedDefault({ value, onChange, label = 'Default reminder for timed events' }) {
  const minutes = value && value.length > 0 ? Number(value[0].minutes) || 0 : null;
  const [custom, setCustom] = useState(null); // {n, unit} | null
  const customActive = custom !== null || (minutes !== null && !TIMED_CHOICES.includes(minutes));
  const pair = custom || (minutes !== null ? fromMinutes(minutes) : { n: 30, unit: 'minutes' });
  const savePair = (p) => {
    setCustom(p);
    onChange([{ minutes: toMinutes(p.n, p.unit) }]);
  };
  const onSelect = (v) => {
    if (v === 'custom') { setCustom(pair); return; }
    setCustom(null);
    onChange(v === '' ? [] : [{ minutes: Number(v) }]);
  };
  return html`<select aria-label=${label}
      value=${customActive ? 'custom' : (minutes === null ? '' : String(minutes))}
      onChange=${(e) => onSelect(e.target.value)}>
      <option value="">None</option>
      ${TIMED_CHOICES.map((m) => html`<option key=${m} value=${String(m)}>${fmtOffsetMinutes(m)}</option>`)}
      <option value="custom">Custom</option>
    </select>
    ${customActive && html`<span class="bc-rem-custom">
      <input class="bc-num" type="number" min="0" max="40320" aria-label=${label + ': custom amount'}
        value=${pair.n}
        onChange=${(e) => savePair({ ...pair, n: Number(e.target.value) || 0 })} />
      <select aria-label=${label + ': custom unit'} value=${pair.unit}
        onChange=${(e) => savePair({ ...pair, unit: e.target.value })}>
        ${REMINDER_UNITS.map((u) => html`<option key=${u} value=${u}>${u}</option>`)}
      </select>
      <span class="bc-rem-after">before start</span>
    </span>`}`;
}

export function AllDayDefault({ value, onChange, label = 'Default reminder for all-day events' }) {
  const entry = value && value.length > 0 ? value[0] : null;
  const days = entry ? Number(entry.daysBefore) || 0 : null;
  const [custom, setCustom] = useState(null); // {n, unit} | null
  const customActive = custom !== null || (days !== null && !ALLDAY_DAYS_CHOICES.some(([d]) => d === days));
  const pair = custom || (days !== null && days > 0 && days % 7 === 0
    ? { n: days / 7, unit: 'weeks' }
    : { n: days == null ? 3 : days, unit: 'days' });
  const save = (patch) => onChange([{ ...(entry || { daysBefore: 1, time: '18:00' }), ...patch }]);
  const savePair = (p) => {
    setCustom(p);
    save({ daysBefore: Math.max(0, Math.min(28, Math.round((Number(p.n) || 0) * (p.unit === 'weeks' ? 7 : 1)))) });
  };
  const onSelect = (v) => {
    if (v === 'custom') { setCustom(pair); return; }
    setCustom(null);
    if (v === '') onChange([]);
    else save({ daysBefore: Number(v) });
  };
  return html`<select aria-label=${label}
      value=${customActive ? 'custom' : (days === null ? '' : String(days))}
      onChange=${(e) => onSelect(e.target.value)}>
      <option value="">None</option>
      ${ALLDAY_DAYS_CHOICES.map(([d, l]) => html`<option key=${d} value=${String(d)}>${l}</option>`)}
      <option value="custom">Custom</option>
    </select>
    ${customActive && html`<span class="bc-rem-custom">
      <input class="bc-num" type="number" min="0" max="28" aria-label=${label + ': custom amount'}
        value=${pair.n}
        onChange=${(e) => savePair({ ...pair, n: Number(e.target.value) || 0 })} />
      <select aria-label=${label + ': custom unit'} value=${pair.unit}
        onChange=${(e) => savePair({ ...pair, unit: e.target.value })}>
        <option value="days">days</option>
        <option value="weeks">weeks</option>
      </select>
      <span class="bc-rem-after">before</span>
    </span>`}
    ${entry && html`<input type="time" aria-label=${label + ': time'}
      value=${entry.time} onChange=${(e) => e.target.value && save({ time: e.target.value })} />`}`;
}
