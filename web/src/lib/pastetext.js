// What a paste onto the calendar becomes (app/pasteanywhere.js). Pure, so
// the smoke suite can check it.

/**
 * The pasted text, plus the link a copied piece of a web page or email
 * carries in its HTML when the text itself has none ("Halloween party"
 * copied from an invitation, whose title links to the event page). Only one
 * distinct link: a copied list of links says nothing about which.
 */
export function pastedText(plain, html) {
  let text = String(plain || '').trim();
  if (/https?:\/\//i.test(text) || !html) return text;
  const hrefs = new Set();
  for (const m of String(html).matchAll(/href\s*=\s*["']([^"']+)["']/gi)) {
    const href = m[1].replace(/&amp;/g, '&').trim();
    if (/^https?:\/\//i.test(href)) hrefs.add(href);
  }
  if (hrefs.size === 1) text = (text + ' ' + [...hrefs][0]).trim();
  return text;
}
