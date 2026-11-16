// Each filter's card on the Filters page says what it matches (#63, first
// part), and the words follow the matchers on the server: read here from
// their source, so a change there that the sentence no longer describes
// fails this file. Run: node web/tests/filter-words.mjs (smoke.mjs imports
// it). Invented filters only; no clock is read.

import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const { filterMatchText, filterFields, DEFAULT_FIELDS, PROMPT_DESCRIPTION_CHARS, PROMPT_FIELDS } = await import('../src/lib/filterwords.js');

let checks = 0;
const ok = (cond, msg) => { assert.ok(cond, msg); checks++; };
const eq = (a, b, msg) => { assert.deepEqual(a, b, msg); checks++; };

const server = (p) => readFileSync(new URL('../../server/src/' + p, import.meta.url), 'utf8');
const filtersPhp = server('Domain/Filters.php');
const promptPhp = server('Domain/PromptEval.php');
const constList = (src, name) => JSON.parse((src.match(new RegExp('const ' + name + "\\s*=\\s*(\\[[^\\]]*\\])")) || [])[1].replace(/'/g, '"'));
eq(constList(filtersPhp, 'DEFAULT_FIELDS'), DEFAULT_FIELDS, 'a filter with no field list matches the server\'s default fields');
eq(constList(filtersPhp, 'FIELDS'), ['title', 'description', 'location', 'tags'], 'the fields the cards name are the ones the matcher reads');
ok(/mb_stripos\(\$value, \$pattern\) !== false/.test(filtersPhp), 'a keyword is a substring ignoring case');
ok(/'~iu'/.test(filtersPhp), 'a regex runs ignoring case');
ok(/foreach \(\$tags as \$tag\)[\s\S]{0,120}patternHits\(\$type, \$pattern, \(string\) \$tag\)/.test(filtersPhp), 'a tag matches by its name');
eq(Number((promptPhp.match(/DESCRIPTION_EXCERPT_CHARS\s*=\s*(\d+)/) || [])[1]), PROMPT_DESCRIPTION_CHARS, 'the prompt card names the excerpt the model is sent');
const payload = promptPhp.slice(promptPhp.indexOf('function eventPayload'));
const sent = [...payload.slice(payload.indexOf('return ['), payload.indexOf('];')).matchAll(/'(\w+)' =>/g)].map((m) => m[1]);
eq(sent, ['eventId', ...PROMPT_FIELDS], 'the prompt card names every field the model is sent, and no other');
ok(/e\.source = 'feed'/.test(promptPhp), 'only events that came in from subscribed and Google calendars are judged');

eq(filterFields({ config: {} }), DEFAULT_FIELDS, 'no field list: the old default');
eq(filterFields({ config: { fields: ['tags', 'title'] } }), ['title', 'tags'], 'fields in the page\'s order');
const words = (f) => filterMatchText(f).join(' ');
eq(words({ type: 'keyword', action: 'hide', config: { pattern: 'webinar', fields: ['title'] } }),
  'Events are hidden when this text appears in their title, ignoring case.', 'keyword, title');
eq(words({ type: 'keyword', action: 'highlight', config: { pattern: 'concert', fields: ['title', 'tags'] } }),
  'Events are highlighted when this text appears in their title or the name of one of their tags, ignoring case.', 'keyword, title and tags');
eq(words({ type: 'keyword', action: 'dim', config: { pattern: 'x', fields: ['tags'] } }),
  'Events are dimmed when this text appears in the name of one of their tags, ignoring case.', 'keyword, tags alone');
eq(words({ type: 'regex', action: 'dim', config: { pattern: 'a|b', fields: ['title', 'description', 'location', 'tags'] } }),
  'Events are dimmed when this pattern finds a match in their title, description, location or the name of one of their tags, ignoring case.', 'regex, every field');
eq(words({ type: 'keyword', action: 'hide', config: { pattern: 'x' } }),
  'Events are hidden when this text appears in their title, description or location, ignoring case.', 'a saved filter with no field list');
eq(filterMatchText({ type: 'prompt', action: 'highlight', config: { prompt: 'outdoor things' } }), [
  "Looks at each event's title, the first 300 characters of its description, its location and its start time, and nothing else. Only events on subscribed and Google calendars are judged.",
  'Events judged not to fit are highlighted; events not judged yet stay as they are.',
], 'an AI prompt filter says what it looks at, and what it does');


console.log('Filter words: ' + checks + ' checks (each card says what its filter matches, as the server matches it)');
