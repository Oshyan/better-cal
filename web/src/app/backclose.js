// The phone's Back closes an overlay (bottom-bar sheets, quick add, the day
// list, jump to date) instead of leaving the app. Each open overlay holds one
// history entry, added when it opens (always from a tap, so Chrome on Android
// keeps the entry: it skips ones a page adds without a gesture) and taken
// back off when it closes any other way.
//
// Handing over to another surface that keeps history (the editor, the event
// sheet, search, or another overlay here) passes the entry on rather than
// removing it and adding a new one, so one Back still closes what is showing.
// The event sheet, editor and search take an entry marked bcSheet over.
//
// Phone only, like the other Back handling: on a desktop the browser's Back
// button keeps its usual meaning.

import { useEffect } from '../../vendor/index.js';
import { state } from './store.js';
import { PHONE_QUERY } from '../lib/breakpoints.js';

let seq = 0;
const isPhone = () => { try { return matchMedia(PHONE_QUERY).matches; } catch { return false; } };

/** Something that keeps its own history entry is open now (it can take ours). */
function handedOver() {
  return !!(state.editor || state.popover || state.searchOpen || state.quickAddOpen
    || state.phoneSheet || state.jumpOpen || state.expandedDay);
}

/**
 * @param {boolean} active the overlay is showing
 * @param {() => void} close closes it (called on Back)
 */
export function useBackClose(active, close) {
  useEffect(() => {
    if (!active || !isPhone()) return undefined;
    const id = ++seq;
    if (history.state && history.state.bcHandoff) history.replaceState({ bcOverlay: id }, '');
    else history.pushState({ bcOverlay: id }, '');
    let popped = false;
    const onPop = () => {
      // Back closed something opened over this one: still showing.
      if (history.state && history.state.bcOverlay === id) return;
      popped = true;
      close();
    };
    window.addEventListener('popstate', onPop);
    return () => {
      window.removeEventListener('popstate', onPop);
      if (popped || !history.state || history.state.bcOverlay !== id) return;
      if (handedOver()) history.replaceState({ bcSheet: 1, bcHandoff: 1 }, '');
      else history.back();
    };
  }, [active]); // eslint-disable-line
}
