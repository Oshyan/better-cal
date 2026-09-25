// The calendar picker for creating and editing events: a native <select>
// whose options carry the calendar's colour dot. Where the browser supports a
// customizable select (appearance: base-select, Chrome 135+), the dots show
// in the list and on the closed button, so a calendar is picked by colour at
// a glance; elsewhere it is the same plain select as before (the dot spans
// have no text, so each option still reads as the calendar's name).

import { html } from '../../vendor/index.js';
import { DEFAULT_COLOR } from '../lib/color.js';

export function CalendarSelect({ calendars, value, onChange, className = '', ariaLabel }) {
  return html`<select
    class=${'bc-calselect' + (className ? ' ' + className : '')}
    value=${value}
    aria-label=${ariaLabel}
    onChange=${(e) => onChange(e.target.value)}
  >
    <button type="button" class="bc-calselect-btn"><selectedcontent></selectedcontent></button>
    ${calendars.map((c) => html`<option key=${c.id} value=${c.id}>
      <span class="bc-caldot" style=${'background:' + (c.color || DEFAULT_COLOR)}></span><span class="bc-calselect-name">${c.name}</span>
    </option>`)}
  </select>`;
}
