// Each filter's card on the Filters page says what it matches (#63, first
// part), and the words follow the matchers on the server: read here from
// their source, so a change there that the sentence no longer describes
// fails this file. Run: node web/tests/filter-words.mjs (smoke.mjs imports
// it). Invented filters only; no clock is read.

import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const { filterMatchText, filterFields, defaultAppliesTo, appliesToOf, actionChip, APPLIES_TO, DEFAULT_FIELDS, PROMPT_DESCRIPTION_CHARS, PROMPT_FIELDS } = await import('../src/lib/filterwords.js');

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
eq(filterMatchText({ type: 'prompt', action: 'highlight', appliesTo: 'match', config: { prompt: 'outdoor things' } }), [
  "Looks at each event's title, the first 300 characters of its description, its location and its start time, and nothing else. Only events on subscribed and Google calendars are judged.",
  'Events judged to fit the description are highlighted; events not judged yet stay as they are.',
], 'an AI prompt filter says what it looks at, and what it does');

// Which events a filter's action takes: the server's one function
// (Filters::takesAction) from its side and whether the event matches, the
// defaults by kind, and every action and side said on the card.
ok(/function takesAction\(string \$appliesTo, \?bool \$matches\): bool\s*\{\s*if \(\$matches === null\) \{\s*return false;\s*\}\s*return \$appliesTo === 'nonmatch' \? !\$matches : \$matches;/.test(filtersPhp),
  'the server: a side and a match decide, and not known takes no action');
eq(constList(filtersPhp, 'APPLIES_TO'), APPLIES_TO, 'the sides the cards name are the server\'s');
ok(/function defaultAppliesTo\(string \$type\): string\s*\{\s*return \$type === 'prompt' \? 'nonmatch' : 'match';/.test(filtersPhp), 'the server\'s default side by kind');
eq(['prompt', 'keyword', 'regex'].map(defaultAppliesTo), ['nonmatch', 'match', 'match'], 'the page\'s, the same');
eq(appliesToOf({ type: 'prompt', appliesTo: 'match' }), 'match', 'a saved side is read as saved');
const six = (type, config) => Object.fromEntries(['hide', 'dim', 'highlight'].flatMap((action) => APPLIES_TO.map((side) => [action + ' ' + side, filterMatchText({ type, action, appliesTo: side, scope: 'global', config }).join(' ')])));
eq(six('prompt', { prompt: 'outdoor things' }), Object.fromEntries(['hidden', 'dimmed', 'highlighted'].flatMap((done, i) => [
  [['hide', 'dim', 'highlight'][i] + ' match', `Looks at each event's title, the first 300 characters of its description, its location and its start time, and nothing else. Only events on subscribed and Google calendars are judged. Events judged to fit the description are ${done}; events not judged yet stay as they are.`],
  [['hide', 'dim', 'highlight'][i] + ' nonmatch', `Looks at each event's title, the first 300 characters of its description, its location and its start time, and nothing else. Only events on subscribed and Google calendars are judged. Events judged not to fit the description are ${done}; events not judged yet stay as they are.`],
])), 'an AI prompt filter: each of the six');
const every = ' That is every other event on every calendar, weather, holidays and trips included.';
eq(six('keyword', { pattern: 'webinar', fields: ['title', 'tags'] }), {
  'hide match': 'Events are hidden when this text appears in their title or the name of one of their tags, ignoring case.',
  'hide nonmatch': 'Events are hidden when this text does not appear in their title or the name of any of their tags, ignoring case.' + every,
  'dim match': 'Events are dimmed when this text appears in their title or the name of one of their tags, ignoring case.',
  'dim nonmatch': 'Events are dimmed when this text does not appear in their title or the name of any of their tags, ignoring case.' + every,
  'highlight match': 'Events are highlighted when this text appears in their title or the name of one of their tags, ignoring case.',
  'highlight nonmatch': 'Events are highlighted when this text does not appear in their title or the name of any of their tags, ignoring case.' + every,
}, 'a keyword filter: each of the six');
eq(six('regex', { pattern: 'web(inar|cast)', fields: ['title'] }), {
  'hide match': 'Events are hidden when this pattern finds a match in their title, ignoring case.',
  'hide nonmatch': 'Events are hidden when this pattern finds no match in their title, ignoring case.' + every,
  'dim match': 'Events are dimmed when this pattern finds a match in their title, ignoring case.',
  'dim nonmatch': 'Events are dimmed when this pattern finds no match in their title, ignoring case.' + every,
  'highlight match': 'Events are highlighted when this pattern finds a match in their title, ignoring case.',
  'highlight nonmatch': 'Events are highlighted when this pattern finds no match in their title, ignoring case.' + every,
}, 'a regex filter: each of the six');
eq(['folder', 'calendar'].map((scope) => filterMatchText({ type: 'keyword', action: 'hide', appliesTo: 'nonmatch', scope, config: { pattern: 'x', fields: ['title'] } })[1]), [
  'That is every other event on the calendars in its folder, weather, holidays and trips included.',
  'That is every other event on its calendar, weather, holidays and trips included.',
], 'the unmatched, said by the filter\'s scope');
eq([actionChip({ type: 'keyword', action: 'hide', appliesTo: 'match' }), actionChip({ type: 'prompt', action: 'dim', appliesTo: 'nonmatch' }), actionChip({ type: 'regex', action: 'highlight' })],
  ['Hides what matches', "Dims what doesn't match", 'Highlights what matches'], 'the card\'s action chip says the side');

console.log('Filter words: ' + checks + ' checks (each card says what its filter matches, as the server matches it)');
