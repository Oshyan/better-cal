// Fuzzy matching for the command palette. Pure, no imports.
//
// Two tiers, because they mean different things to someone typing fast:
// a contiguous substring is what you get when you know the command's name and
// are typing it ("cal" -> "Show all calendars"), while a scattered subsequence
// is initials and abbreviations ("sac" -> the same). Substring always wins, so
// typing more of the real name never demotes the thing you were aiming at.

function isBoundary(s, i) {
  return i === 0 || !/[a-z0-9]/.test(s[i - 1]);
}

/**
 * Score `text` against `query`. Higher is better; -1 means no match.
 * An empty query matches everything at 0 so callers can skip a special case.
 */
export function fuzzyScore(text, query) {
  const lt = String(text || '').toLowerCase();
  const lq = String(query || '').trim().toLowerCase().replace(/\s+/g, ' ');
  if (!lq) return 0;
  if (!lt) return -1;

  const sub = lt.indexOf(lq);
  if (sub >= 0) {
    // Earlier is better, and starting a word beats landing mid-word.
    return 1000 - Math.min(sub, 40) * 2 + (isBoundary(lt, sub) ? 60 : 0);
  }

  // Subsequence. Spaces are dropped from the query so "mv" and "m v" both
  // reach "Month view".
  let from = 0;
  let score = 300;
  let last = -2;
  for (const ch of lq.replace(/ /g, '')) {
    const at = lt.indexOf(ch, from);
    if (at < 0) return -1;
    if (at === last + 1) score += 12;
    else if (isBoundary(lt, at)) score += 8;
    else score -= Math.min(at - last, 8);
    last = at;
    from = at + 1;
  }
  return score;
}

/**
 * Rank items by how well they match, across all of an item's searchable
 * strings. Two readings are tried and the better one wins:
 *
 *   whole-query — the entire query against one field. This is what makes
 *   typing a command's actual name rank that command first.
 *
 *   token-wise — every whitespace-separated word has to land somewhere among
 *   the fields, not necessarily the same one. "ada gone" is a name in the
 *   label plus a synonym in the keywords, and no single field holds both;
 *   without this the palette's headline case ("<person> gone") matched
 *   nothing at all.
 *
 * Ties keep the input order, so a caller's own ordering survives an
 * ambiguous query.
 *
 * @param {Array} items
 * @param {string} query
 * @param {(item:any)=>Array<string|null|undefined>} fields
 */
export function rankByFuzzy(items, query, fields) {
  const q = String(query || '').trim().toLowerCase().replace(/\s+/g, ' ');
  const tokens = q ? q.split(' ') : [];
  const scored = [];

  for (let i = 0; i < items.length; i++) {
    const item = items[i];
    const fs = fields(item).filter(Boolean).map(String);
    if (fs.length === 0) continue;
    if (!q) { scored.push({ item, score: 0, i }); continue; }

    let whole = -1;
    for (const f of fs) {
      const s = fuzzyScore(f, q);
      if (s > whole) whole = s;
    }

    let sum = 0;
    let all = tokens.length > 0;
    for (const tok of tokens) {
      let best = -1;
      for (const f of fs) {
        const s = fuzzyScore(f, tok);
        if (s > best) best = s;
      }
      if (best < 0) { all = false; break; }
      sum += best;
    }
    // Slightly below an equivalent whole-query hit, so an exact command name
    // still outranks a scattered multi-word match that scores the same.
    const tokenScore = all ? (sum / tokens.length) - 1 : -1;

    const score = Math.max(whole, tokenScore);
    if (score >= 0) scored.push({ item, score, i });
  }

  scored.sort((a, b) => (b.score - a.score) || (a.i - b.i));
  return scored.map((r) => r.item);
}
