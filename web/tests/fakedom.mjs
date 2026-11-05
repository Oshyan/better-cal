// A small stand-in DOM for drawing a whole component with Preact under
// node: enough for the sidebar and the editor to render, take a click or a
// change, and be read back. Selectors: .class, tag, [attr] and
// [attr="value"], joined without spaces; a comma between alternatives.
// Install it, draw, read, then put the real globals back with restore().

export function fakeDom({ width = 1280 } = {}) {
  let doc = null;
  const matchOne = (node, sel) => {
    const parts = sel.match(/(\.[\w-]+|\[[^\]]+\]|^[a-z][\w-]*)/g) || [];
    return parts.every((p) => {
      if (p[0] === '.') return node.classes.includes(p.slice(1));
      if (p[0] === '[') {
        const m = p.slice(1, -1).match(/^([\w-]+)(?:="?([^"]*)"?)?$/);
        if (!m) return false;
        const v = node.getAttribute(m[1]);
        return m[2] === undefined ? v != null : v === m[2];
      }
      return node.localName === p;
    });
  };
  class FakeNode {
    constructor(nodeType, name = '') {
      this.nodeType = nodeType; this.localName = name; this.tagName = name.toUpperCase();
      this.namespaceURI = 'http://www.w3.org/1999/xhtml';
      this.childNodes = []; this.parentNode = null; this.attrs = new Map(); this.listeners = {};
      if (nodeType === 1) {
        const styleProps = {};
        this.style = new Proxy(styleProps, { get: (t, k) => (k === 'setProperty' ? (a, b) => { t[a] = b; } : k === 'removeProperty' ? (a) => { delete t[a]; } : k === 'getPropertyValue' ? (a) => t[a] || '' : t[k] || ''), set: (t, k, v) => { t[k] = v; return true; } });
        this.dataset = {};
        const self = this;
        this.classList = {
          add: (...c) => self.setAttribute('class', [...new Set([...self.classes, ...c])].join(' ')),
          remove: (...c) => self.setAttribute('class', self.classes.filter((x) => !c.includes(x)).join(' ')),
          toggle: (c, on) => { const has = self.classes.includes(c); const want = on === undefined ? !has : on; if (want) self.classList.add(c); else self.classList.remove(c); return want; },
          contains: (c) => self.classes.includes(c),
        };
      }
    }
    get ownerDocument() { return doc; }
    get firstChild() { return this.childNodes[0] || null; }
    get lastChild() { return this.childNodes[this.childNodes.length - 1] || null; }
    get children() { return this.childNodes.filter((c) => c.nodeType === 1); }
    get nextSibling() { const p = this.parentNode; return p ? p.childNodes[p.childNodes.indexOf(this) + 1] || null : null; }
    get previousSibling() { const p = this.parentNode; return p ? p.childNodes[p.childNodes.indexOf(this) - 1] || null : null; }
    get parentElement() { return this.parentNode && this.parentNode.nodeType === 1 ? this.parentNode : null; }
    get textContent() { return this.nodeType === 3 ? String(this.data) : this.childNodes.map((c) => c.textContent).join(''); }
    set textContent(t) { this.childNodes = []; if (t !== '') this.appendChild(doc.createTextNode(t)); }
    get attributes() { return [...this.attrs].map(([name, value]) => ({ name, value })); }
    get classes() { return (this.attrs.get('class') || '').split(/\s+/).filter(Boolean); }
    // A field's value and a box's tick live on the element, as in a browser,
    // so what Preact sets and what a change event reads are the same.
    get value() { return this.attrs.has('value') ? this.attrs.get('value') : ''; }
    set value(v) { this.attrs.set('value', String(v)); }
    get checked() { return this.attrs.has('checked') && this.attrs.get('checked') !== 'false'; }
    set checked(v) { if (v) this.attrs.set('checked', ''); else this.attrs.delete('checked'); }
    get className() { return this.attrs.get('class') || ''; }
    set className(v) { this.attrs.set('class', String(v)); }
    get isConnected() { for (let n = this; n; n = n.parentNode) if (n === doc.body) return true; return false; }
    insertBefore(node, ref) {
      if (node.parentNode) node.parentNode.removeChild(node);
      const i = ref ? this.childNodes.indexOf(ref) : -1;
      if (i < 0) this.childNodes.push(node); else this.childNodes.splice(i, 0, node);
      node.parentNode = this;
      return node;
    }
    appendChild(node) { return this.insertBefore(node, null); }
    append(...nodes) { for (const n of nodes) this.appendChild(typeof n === 'string' ? doc.createTextNode(n) : n); }
    removeChild(node) { this.childNodes = this.childNodes.filter((c) => c !== node); node.parentNode = null; return node; }
    remove() { if (this.parentNode) this.parentNode.removeChild(this); }
    setAttribute(k, v) { this.attrs.set(k, String(v)); if (k.startsWith('data-')) this.dataset[k.slice(5).replace(/-(\w)/g, (_, c) => c.toUpperCase())] = String(v); }
    getAttribute(k) { return this.attrs.has(k) ? this.attrs.get(k) : null; }
    hasAttribute(k) { return this.attrs.has(k); }
    removeAttribute(k) { this.attrs.delete(k); }
    addEventListener(type, fn) { (this.listeners[type] ||= []).push(fn); }
    removeEventListener(type, fn) { this.listeners[type] = (this.listeners[type] || []).filter((f) => f !== fn); }
    focus() { doc.activeElement = this; }
    blur() { if (doc.activeElement === this) doc.activeElement = doc.body; }
    select() {}
    scrollIntoView() {}
    scrollTo() {}
    setSelectionRange() {}
    get offsetWidth() { return 100; }
    get offsetHeight() { return 20; }
    get clientWidth() { return 100; }
    get clientHeight() { return 20; }
    get scrollHeight() { return 20; }
    contains(n) { for (; n; n = n.parentNode) if (n === this) return true; return false; }
    matches(sel) { return this.nodeType === 1 && sel.split(',').map((s) => s.trim()).some((s) => matchOne(this, s)); }
    closest(sel) { for (let n = this; n && n.nodeType === 1; n = n.parentNode) if (n.matches(sel)) return n; return null; }
    querySelectorAll(sel) {
      const out = [];
      const walk = (n) => { for (const c of n.childNodes) if (c.nodeType === 1) { if (c.matches(sel)) out.push(c); walk(c); } };
      walk(this);
      return out;
    }
    querySelector(sel) { return this.querySelectorAll(sel)[0] || null; }
    getBoundingClientRect() { return { left: 0, top: 0, width: 100, height: 20, right: 100, bottom: 20, x: 0, y: 0 }; }
    getClientRects() { return [this.getBoundingClientRect()]; }
    dispatch(type, extra = {}) {
      const ev = { type, target: this, currentTarget: this, preventDefault() {}, stopPropagation() {}, ...extra };
      for (const fn of [...(this.listeners[type] || [])]) fn.call(this, ev);
    }
    click() { this.dispatch('click'); }
  }
  doc = {
    createElementNS: (ns, name) => new FakeNode(1, name),
    createElement: (name) => new FakeNode(1, name),
    createTextNode: (data) => { const t = new FakeNode(3); t.data = data; return t; },
    createComment: () => new FakeNode(8),
    addEventListener() {}, removeEventListener() {},
    querySelectorAll: (sel) => doc.body.querySelectorAll(sel),
    querySelector: (sel) => doc.body.querySelector(sel),
    getElementById: () => null,
    elementFromPoint: () => null,
    hidden: false, visibilityState: 'visible',
  };
  doc.body = new FakeNode(1, 'body');
  doc.documentElement = new FakeNode(1, 'html');
  doc.head = new FakeNode(1, 'head');
  doc.activeElement = doc.body;
  const listeners = {};
  const win = {
    innerWidth: width, innerHeight: 800, location: { href: 'http://app.test/', pathname: '/', search: '', hash: '' },
    addEventListener(type, fn) { (listeners[type] ||= []).push(fn); },
    removeEventListener(type, fn) { listeners[type] = (listeners[type] || []).filter((f) => f !== fn); },
    matchMedia: () => ({ matches: false, addEventListener() {}, removeEventListener() {}, addListener() {}, removeListener() {} }),
    getComputedStyle: () => ({ getPropertyValue: () => '' }),
    requestAnimationFrame: (cb) => setImmediate(cb), cancelAnimationFrame() {},
    getSelection: () => null,
    scrollTo() {},
  };
  const saved = {};
  const names = ['window', 'document', 'localStorage', 'requestAnimationFrame', 'cancelAnimationFrame', 'getComputedStyle'];
  return {
    doc, win,
    install() {
      for (const n of names) saved[n] = [n in globalThis, globalThis[n]];
      globalThis.window = win;
      globalThis.document = doc;
      const store = new Map();
      globalThis.localStorage = { getItem: (k) => (store.has(k) ? store.get(k) : null), setItem: (k, v) => store.set(k, String(v)), removeItem: (k) => store.delete(k) };
      globalThis.requestAnimationFrame = win.requestAnimationFrame;
      globalThis.cancelAnimationFrame = win.cancelAnimationFrame;
      globalThis.getComputedStyle = win.getComputedStyle;
    },
    restore() {
      for (const n of names) { const [had, v] = saved[n]; if (had) globalThis[n] = v; else delete globalThis[n]; }
    },
  };
}
