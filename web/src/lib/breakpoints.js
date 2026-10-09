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
// A phone turned sideways is wider than both (about 840 to 1000 points), so
// width alone handed it the desktop frame (#125). TURNED catches it: a touch
// screen no taller than 500 points, and no wider than 1000 so a tablet whose
// keyboard shrinks the viewport (interactive-widget=resizes-content) keeps
// its frame while typing. A turned phone is a PHONE (and so COMPACT) too;
// PHONE_WIDE is only the turned case, for what changes with the shape.
//
// CSS can't import these, so app.css repeats them: 640px, 800px and the
// TURNED query. Change both together.
export const PHONE_MAX = 640;
export const COMPACT_MAX = 800;
export const TURNED_QUERY = '(pointer: coarse) and (max-height: 500px) and (max-width: 1000px)';
export const PHONE_QUERY = `(max-width: ${PHONE_MAX}px), ${TURNED_QUERY}`;
export const COMPACT_QUERY = `(max-width: ${COMPACT_MAX}px), ${TURNED_QUERY}`;
export const PHONE_WIDE_QUERY = `${TURNED_QUERY} and (min-width: ${PHONE_MAX + 1}px)`;
export const COARSE_QUERY = '(pointer: coarse)';
