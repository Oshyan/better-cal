// Storage-only helpers live apart from resume.js so logout can clear the
// snapshot without importing the calendar/search UI and creating a module
// cycle through api.js.
export const RESUME_KEY = 'bc-resume';

export function clearResume() {
  for (const name of ['localStorage', 'sessionStorage']) {
    try {
      const storage = globalThis[name];
      if (storage) storage.removeItem(RESUME_KEY);
    } catch { /* storage unavailable */ }
  }
}
