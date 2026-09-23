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
  // [^<>] rather than [^>]: a run like "<a<a<a..." with no ">" used to make
  // every start scan to the end of the text (quadratic, scan 2026-09-23 F22).
  return typeof text === 'string' && /<\/?[a-zA-Z][^<>]*>/.test(text);
}

// A URL found in prose, split from trailing punctuation that is prose, by a
// loop instead of an end-anchored regex (quadratic on long punctuation runs,
// F26). Returns [url, tail]. Longer than 2,048 characters is not a link.
const URL_TAIL = new Set([')', ',', '.', ';', ':', '!', '?', ']']);
export function splitUrlTail(raw) {
  if (typeof raw !== 'string' || raw.length > 2048) return [null, raw];
  let end = raw.length;
  while (end > 0 && URL_TAIL.has(raw[end - 1])) end--;
  return [raw.slice(0, end), raw.slice(end)];
}

// Squire's own auto-link pattern nests a quantifier and backtracks
// exponentially on crafted text in a paste or a description being edited
// (F27/F28). This keeps its groups (1 = web address, 2 = email) without
// nested repetition, drops the bare "domain.tld/" form, and bounds every
// repetition in the pattern itself, so each start position costs at most a
// couple of thousand steps however long the text is. Squire only calls .exec().
const SAFE_LINK_RE = /\b(?:((?:(?:ht|f)tps?:\/\/|www\d{0,3}[.])(?:[^\s()<>]|\([^\s()<>]{0,256}\)){0,2048}(?:[^\s?&`!()\[\]{};:'".,<>«»“”‘’]|\([^\s()<>]{0,256}\)))|([\w\-.%+]{1,64}@(?:[\w\-]{1,63}\.){1,8}[a-z]{2,24}\b))/i;
export const safeLinkMatcher = {
  exec(text) {
    return typeof text === 'string' ? SAFE_LINK_RE.exec(text) : null;
  },
};

// Flatten a description (HTML or plain) to text: blocks and <br> become
// newlines, tags drop, common entities decode. Pure string ops so the
// popover preview and node tests share it.
// Closed <script>/<style>/<iframe> blocks removed by one forward scan (a
// lazy regex here rescanned to the end from every opening tag: F22).
function dropBlocks(text) {
  const lower = text.toLowerCase();
  let out = '';
  let pos = 0;
  for (;;) {
    let best = -1;
    let tag = '';
    for (const t of ['script', 'style', 'iframe']) {
      const i = lower.indexOf('<' + t, pos);
      if (i !== -1 && (best === -1 || i < best)) { best = i; tag = t; }
    }
    if (best === -1) break;
    const gt = lower.indexOf('>', best);
    const close = gt === -1 ? -1 : lower.indexOf('</' + tag, gt);
    if (close === -1) break; // unclosed: keep the rest as text
    const end = lower.indexOf('>', close);
    out += text.slice(pos, best);
    pos = end === -1 ? text.length : end + 1;
  }
  return out + text.slice(pos);
}

export function stripToText(text) {
  if (typeof text !== 'string' || text === '') return '';
  if (!hasHtml(text)) return text;
  let s = dropBlocks(text);
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
