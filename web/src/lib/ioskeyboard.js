// The on-screen keyboard on iPhone and iPad.
//
// iOS raises the keyboard only for a focus() made inside the tap itself.
// Search, quick add, the date jump and the editor focus their box once it has
// rendered, a moment after the tap, so on iOS the box was focused with no
// keyboard and needed a second tap. primeKeyboard(), called from the tap
// handler, focuses a hidden input at once, so the keyboard comes up; the real
// box takes focus when it renders and the keyboard stays with it (the usual
// "focus proxy"). A primer still holding focus a moment later, because nothing
// took it, lets go so the keyboard doesn't linger. Android and desktop
// browsers raise the keyboard from the later focus, so this does nothing
// there.

/** iPhone, iPad or iPod; iPadOS in desktop mode reports a Mac with touch. */
export function isIOS() {
  try {
    const ua = navigator.userAgent || '';
    return /iPhone|iPad|iPod/.test(ua) || (/Macintosh/.test(ua) && navigator.maxTouchPoints > 1);
  } catch {
    return false;
  }
}

const RELEASE_MS = 1500;
let primer = null;

/** Call first thing in a tap handler that opens a box meant for typing. */
export function primeKeyboard() {
  if (!isIOS()) return;
  try {
    if (!primer || !primer.isConnected) {
      primer = document.createElement('input');
      primer.type = 'text';
      primer.className = 'bc-kbd-primer';
      primer.tabIndex = -1;
      // Labelled, not aria-hidden: it holds focus for a moment, and focus on
      // something hidden from screen readers leaves VoiceOver nowhere.
      primer.setAttribute('aria-label', 'Text entry');
      primer.setAttribute('autocomplete', 'off');
      document.body.appendChild(primer);
    }
    primer.focus({ preventScroll: true });
    const p = primer;
    setTimeout(() => { if (document.activeElement === p) p.blur(); }, RELEASE_MS);
  } catch { /* the keyboard just waits for a tap, as before */ }
}
