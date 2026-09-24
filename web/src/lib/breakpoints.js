// The app's two width breakpoints, defined once (mobile audit, 0.3.x). Before
// this the month grid switched to its phone layout at 600px, the day sheet at
// 640, the time grid and toolbar at 800, so between 600 and 800 pixels screens
// disagreed about which layout they were in.
//
// - PHONE: a phone held upright. The phone frame (thin top bar, bottom action
//   bar), sheets instead of popovers, compact month pills.
// - COMPACT: small tablets, split screens, a narrow window. The sidebar
//   becomes a drawer and the toolbar compacts, but the desktop frame stays.
//
// Touch is its own axis, not a width: (pointer: coarse) sets tap-target size
// and shows controls that desktop reveals on hover.
//
// CSS can't import these, so app.css repeats the numbers: 640px and 800px.
// Change both together.
export const PHONE_MAX = 640;
export const COMPACT_MAX = 800;
export const PHONE_QUERY = `(max-width: ${PHONE_MAX}px)`;
export const COMPACT_QUERY = `(max-width: ${COMPACT_MAX}px)`;
export const COARSE_QUERY = '(pointer: coarse)';
