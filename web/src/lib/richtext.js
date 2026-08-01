// Rich text helpers for event descriptions. Descriptions are one field that
// holds either plain text (legacy events, quick add, API clients) or
// sanitized HTML (the rich editor). The server sanitizes on write
// (Domain/Sanitize.php); the client mirrors the same allowlist on render as
// defense in depth, which also covers feed events whose descriptions were
// imported verbatim.
//
// hasHtml/stripToText are pure string functions (node-testable in smoke.mjs);
// sanitizeHtml and textToHtml need a DOM and only run in the browser.

const ALLOWED = new Set(['P', 'BR', 'B', 'STRONG', 'I', 'EM', 'U', 'A', 'UL', 'OL', 'LI', 'DIV']);
const DROPPED = new Set(['SCRIPT', 'STYLE', 'IFRAME', 'OBJECT', 'EMBED', 'NOSCRIPT', 'HEAD', 'TITLE', 'SVG', 'MATH', 'TEMPLATE', 'FORM', 'INPUT', 'BUTTON', 'SELECT', 'TEXTAREA']);

// A '<' immediately followed by a tag name (or '/') means markup, not prose;
// "a < b" and "<3" stay plain text. Mirrors Sanitize::isHtml server-side.
export function hasHtml(text) {
  return typeof text === 'string' && /<\/?[a-zA-Z][^>]*>/.test(text);
}

// Flatten a description (HTML or plain) to text: blocks and <br> become
// newlines, tags drop, common entities decode. Pure string ops so the
// popover preview and node tests share it.
export function stripToText(text) {
  if (typeof text !== 'string' || text === '') return '';
  if (!hasHtml(text)) return text;
  let s = text.replace(/<(script|style|iframe)\b[^>]*>[\s\S]*?<\/\1\s*>/gi, '');
  s = s.replace(/<br\s*\/?>/gi, '\n');
  s = s.replace(/<\/(p|div|li|ul|ol|h[1-6]|blockquote|tr)\s*>/gi, '\n');
  s = s.replace(/<[^>]+>/g, '');
  s = s.replace(/&nbsp;/gi, ' ')
    .replace(/&lt;/gi, '<')
    .replace(/&gt;/gi, '>')
    .replace(/&quot;/gi, '"')
    .replace(/&#0?39;/g, "'")
    .replace(/&amp;/gi, '&');
  s = s.replace(/\n{3,}/g, '\n\n');
  return s.trim();
}

// Allowlist-sanitize an HTML fragment for rendering (browser only). Unknown
// tags unwrap keeping their text; dangerous tags drop with their contents;
// every attribute is stripped except a validated http/https href.
export function sanitizeHtml(input) {
  if (typeof document === 'undefined') return '';
  const doc = new DOMParser().parseFromString('<body>' + input + '</body>', 'text/html');
  const out = document.createElement('div');
  const copy = (from, to) => {
    for (const node of from.childNodes) {
      if (node.nodeType === Node.TEXT_NODE) {
        to.appendChild(document.createTextNode(node.data));
        continue;
      }
      if (node.nodeType !== Node.ELEMENT_NODE) continue;
      const tag = node.tagName;
      if (DROPPED.has(tag)) continue;
      if (!ALLOWED.has(tag)) { copy(node, to); continue; }
      if (tag === 'A') {
        const href = (node.getAttribute('href') || '').trim();
        if (!/^https?:\/\//i.test(href)) { copy(node, to); continue; }
        const a = document.createElement('a');
        a.setAttribute('href', href);
        a.setAttribute('target', '_blank');
        a.setAttribute('rel', 'noopener noreferrer');
        copy(node, a);
        to.appendChild(a);
        continue;
      }
      const el = document.createElement(tag);
      copy(node, el);
      to.appendChild(el);
    }
  };
  copy(doc.body, out);
  return out.innerHTML;
}

// Plain text -> minimal HTML for seeding the editor (escape + line breaks).
export function textToHtml(text) {
  if (typeof document === 'undefined') return '';
  const div = document.createElement('div');
  div.textContent = text || '';
  return div.innerHTML.replace(/\n/g, '<br>');
}

// True when editor HTML holds no actual content (empty divs/brs only).
export function isEmptyHtml(html) {
  if (!html) return true;
  return stripToText(html).trim() === '';
}
