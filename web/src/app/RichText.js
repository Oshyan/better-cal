// RichText: Squire-backed WYSIWYG editor for event descriptions with a
// minimal toolbar (bold, italic, underline, lists, link, clear formatting).
// Squire is vendored (web/vendor/squire/squire-raw.js, no deps) and loaded
// via a script tag like Leaflet; it exposes window.Squire.
//
// The component is uncontrolled after seeding: `seed` (plain text or HTML)
// initializes the editor whenever `seedKey` changes, and every edit reports
// the current HTML through onChange. The server sanitizes on write; the seed
// is client-sanitized too so stored feed HTML can never execute here.

import { html, useState, useRef, useEffect } from '../../vendor/index.js';
import { hasHtml, sanitizeHtml, textToHtml } from '../lib/richtext.js';

function loadScript(src, ready) {
  return new Promise((resolve, reject) => {
    if (ready()) { resolve(); return; }
    const script = document.createElement('script');
    script.src = src;
    script.onload = () => (ready() ? resolve() : reject(new Error(src + ' loaded but global missing')));
    script.onerror = () => reject(new Error(src + ' failed to load'));
    document.head.appendChild(script);
  });
}

let squirePromise = null;
function loadSquire() {
  if (!squirePromise) {
    // squire-raw.js requires DOMPurify as an external global (the whole point
    // of the -raw build), so purify must load first or the Squire constructor
    // throws "DOMPurify is not defined".
    squirePromise = loadScript('/assets/vendor/squire/purify.min.js', () => !!window.DOMPurify)
      .then(() => loadScript('/assets/vendor/squire/squire-raw.js', () => !!window.Squire))
      .then(() => window.Squire);
    squirePromise.catch(() => { squirePromise = null; }); // allow retry on next mount
  }
  return squirePromise;
}

function seedHtml(seed) {
  if (!seed) return '';
  return hasHtml(seed) ? sanitizeHtml(seed) : textToHtml(seed);
}

const LIST_UL = html`<svg viewBox="0 0 16 16" width="14" height="14" aria-hidden="true"><circle cx="2.5" cy="3.5" r="1.4" fill="currentColor"/><circle cx="2.5" cy="8" r="1.4" fill="currentColor"/><circle cx="2.5" cy="12.5" r="1.4" fill="currentColor"/><path d="M6 3.5h8M6 8h8M6 12.5h8" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>`;
const LIST_OL = html`<svg viewBox="0 0 16 16" width="14" height="14" aria-hidden="true"><text x="0" y="5.4" font-size="5.4" fill="currentColor">1</text><text x="0" y="10.4" font-size="5.4" fill="currentColor">2</text><text x="0" y="15.4" font-size="5.4" fill="currentColor">3</text><path d="M6 3.5h8M6 8h8M6 12.5h8" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>`;
const LINK_ICON = html`<svg viewBox="0 0 16 16" width="14" height="14" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"><path d="M6.5 9.5l3-3"/><path d="M7.5 4.5l1.2-1.2a2.7 2.7 0 013.8 3.8L11.3 8.3"/><path d="M8.5 11.5l-1.2 1.2a2.7 2.7 0 01-3.8-3.8l1.2-1.2"/></svg>`;
const CLEAR_ICON = html`<svg viewBox="0 0 16 16" width="14" height="14" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"><path d="M3 13h10"/><path d="M6.5 10.5L11 3l2 2-6.2 6.8z"/><path d="M4.5 12.5l2-2"/></svg>`;

export function RichText({ seed, seedKey, onChange, ariaLabel }) {
  const elRef = useRef(null);
  const editorRef = useRef(null);
  const [ready, setReady] = useState(false);
  const [formats, setFormats] = useState({}); // {B, I, U, UL, OL, A}
  const onChangeRef = useRef(onChange);
  onChangeRef.current = onChange;

  // Create the editor once per mount.
  useEffect(() => {
    let disposed = false;
    loadSquire().then((Squire) => {
      if (disposed || !elRef.current) return;
      const editor = new Squire(elRef.current);
      editorRef.current = editor;
      const readFormats = () => setFormats({
        B: editor.hasFormat('B'),
        I: editor.hasFormat('I'),
        U: editor.hasFormat('U'),
        UL: editor.hasFormat('UL'),
        OL: editor.hasFormat('OL'),
        A: editor.hasFormat('A'),
      });
      editor.addEventListener('input', () => {
        onChangeRef.current(editor.getHTML());
        readFormats();
      });
      editor.addEventListener('pathChange', readFormats);
      editor.setHTML(seedHtml(seed));
      setReady(true);
    }).catch((e) => {
      // Loud failure: a silently-degraded editor looks like data loss to the
      // user (typing works, nothing saves). Surface it instead.
      console.error('Rich text editor failed to initialize:', e);
      // Plain-text fallback that still SAVES: without Squire there is no
      // input event wiring, which reads as data loss. Wire the native one.
      const el = elRef.current;
      if (el && !disposed) {
        el.setAttribute('contenteditable', 'true');
        el.textContent = seed ? String(seed).replace(/<[^>]*>/g, '') : '';
        el.addEventListener('input', () => onChangeRef.current(el.textContent || ''));
      }
      setReady(true);
    });
    return () => {
      disposed = true;
      if (editorRef.current) {
        editorRef.current.destroy();
        editorRef.current = null;
      }
    };
  }, []); // eslint-disable-line
  // Re-seed when the edited event changes under a mounted editor.
  const seededKey = useRef(seedKey);
  useEffect(() => {
    if (seededKey.current === seedKey) return;
    seededKey.current = seedKey;
    if (editorRef.current) editorRef.current.setHTML(seedHtml(seed));
  }, [seedKey]); // eslint-disable-line

  const cmd = (fn) => (e) => {
    e.preventDefault(); // keep the selection and focus in the editor
    const editor = editorRef.current;
    if (editor) { fn(editor); editor.focus(); }
  };
  const toggle = (tag, on, off) => cmd((ed) => (ed.hasFormat(tag) ? off(ed) : on(ed)));

  const makeLink = cmd((ed) => {
    if (ed.hasFormat('A')) { ed.removeLink(); return; }
    let url = window.prompt('Link URL (https://...)');
    if (!url) return;
    url = url.trim();
    if (!/^https?:\/\//i.test(url)) url = 'https://' + url;
    ed.makeLink(url);
  });

  const btn = (label, title, active, onMouseDown, content) => html`<button
    type="button"
    class=${'bc-rt-btn' + (active ? ' is-active' : '')}
    title=${title}
    aria-label=${title}
    aria-pressed=${!!active}
    tabindex="-1"
    onMouseDown=${onMouseDown}
  >${content || label}</button>`;

  return html`<div class="bc-rt">
    <div class="bc-rt-bar" role="toolbar" aria-label="Text formatting">
      ${btn('B', 'Bold', formats.B, toggle('B', (ed) => ed.bold(), (ed) => ed.removeBold()), html`<span class="bc-rt-b">B</span>`)}
      ${btn('I', 'Italic', formats.I, toggle('I', (ed) => ed.italic(), (ed) => ed.removeItalic()), html`<span class="bc-rt-i">I</span>`)}
      ${btn('U', 'Underline', formats.U, toggle('U', (ed) => ed.underline(), (ed) => ed.removeUnderline()), html`<span class="bc-rt-u">U</span>`)}
      <span class="bc-rt-sep" aria-hidden="true"></span>
      ${btn('', 'Bullet list', formats.UL, toggle('UL', (ed) => ed.makeUnorderedList(), (ed) => ed.removeList()), LIST_UL)}
      ${btn('', 'Numbered list', formats.OL, toggle('OL', (ed) => ed.makeOrderedList(), (ed) => ed.removeList()), LIST_OL)}
      <span class="bc-rt-sep" aria-hidden="true"></span>
      ${btn('', formats.A ? 'Remove link' : 'Add link', formats.A, makeLink, LINK_ICON)}
      ${btn('', 'Clear formatting', false, cmd((ed) => ed.removeAllFormatting()), CLEAR_ICON)}
    </div>
    <div
      class=${'bc-rt-editor' + (ready ? '' : ' is-loading')}
      ref=${elRef}
      aria-label=${ariaLabel || 'Description'}
    ></div>
  </div>`;
}
