// Shared inline stroke icons for event surfaces (popover, detail, agenda).
// Same visual language as the sidebar's Icon: 16 grid, 1.4px rounded stroke.

import { html } from '../../vendor/index.js';

export function ThumbIcon({ dir = 'up', size = 13 }) {
  const d = dir === 'up'
    ? 'M4.8 12.9V7.3l2.9-5c.9 0 1.6.7 1.6 1.6 0 .2 0 .3-.1.5l-.5 1.9h3.5c.9 0 1.6.8 1.4 1.7l-.6 3.5c-.1.7-.7 1.2-1.4 1.2zM4.8 7.3H2.4v5.6h2.4'
    : 'M4.8 3.1v5.6l2.9 5c.9 0 1.6-.7 1.6-1.6 0-.2 0-.3-.1-.5l-.5-1.9h3.5c.9 0 1.6-.8 1.4-1.7l-.6-3.5c-.1-.7-.7-1.2-1.4-1.2zM4.8 8.7H2.4V3.1h2.4';
  return html`<svg
    viewBox="0 0 16 16" width=${size} height=${size} aria-hidden="true"
    fill="none" stroke="currentColor" stroke-width="1.4"
    stroke-linecap="round" stroke-linejoin="round"
  ><path d=${d} /></svg>`;
}
