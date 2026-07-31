// Global keyboard map.
// c quick-add, / search, t today, v cycle views, 1-6 direct view,
// arrows navigate anchor, Esc closes overlays.

import { state, set } from './store.js';
import { VIEWS, setView, cycleView, goToday, navigate, closeOverlays } from './actions.js';

function isTyping() {
  const el = document.activeElement;
  if (!el) return false;
  const tag = el.tagName;
  return tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || el.isContentEditable;
}

export function installKeyboard() {
  const onKey = (e) => {
    if (e.key === 'Escape') {
      if (closeOverlays()) e.preventDefault();
      return;
    }
    if (isTyping() || e.metaKey || e.ctrlKey || e.altKey) return;
    switch (e.key) {
      case 'c':
        e.preventDefault();
        set({ quickAddOpen: true });
        break;
      case '/':
        e.preventDefault();
        set({ searchOpen: true });
        break;
      case 't':
        e.preventDefault();
        goToday();
        break;
      case 'v':
        e.preventDefault();
        cycleView();
        break;
      case '1': case '2': case '3': case '4': case '5': case '6':
        e.preventDefault();
        setView(VIEWS[Number(e.key) - 1]);
        break;
      case 'ArrowLeft':
        e.preventDefault();
        navigate(state.view === 'day' ? -1 : -1);
        break;
      case 'ArrowRight':
        e.preventDefault();
        navigate(1);
        break;
      case 'ArrowUp':
        e.preventDefault();
        navigate(-7);
        break;
      case 'ArrowDown':
        e.preventDefault();
        navigate(7);
        break;
    }
  };
  window.addEventListener('keydown', onKey);
  return () => window.removeEventListener('keydown', onKey);
}
