// What a filter matches, in the words its card on the Filters page says
// (#63). Each sentence is written from the matcher that runs on the server,
// and the smoke suite reads those matchers to keep them in step:
//
// - keyword: Filters::matchedField with patternHits, a substring of each
//   chosen field ignoring case (mb_stripos), a tag by each tag's name;
// - regex: the same fields, the pattern run case-insensitively (delimit, ~iu);
// - AI prompt: PromptEval::eventPayload is all the model sees (the title, the
//   description cut at DESCRIPTION_EXCERPT_CHARS, the location and the start),
//   and PromptEval::pendingEvents judges only events that came in from
//   outside (source 'feed': subscribed calendars and Google calendars).
//   Filters::promptActionApplies reads the verdict: hide takes the events
//   judged not to fit the description, dim and highlight the ones judged to
//   fit; no verdict yet takes no action.

/** Saved filters without a field list match these (server: Filters::DEFAULT_FIELDS). */
export const DEFAULT_FIELDS = ['title', 'description', 'location'];
/** The characters of a description an AI prompt filter is shown (server: PromptEval::DESCRIPTION_EXCERPT_CHARS). */
export const PROMPT_DESCRIPTION_CHARS = 300;
/** What an AI prompt filter is shown of an event, besides its id (server: PromptEval::eventPayload). */
export const PROMPT_FIELDS = ['title', 'description', 'location', 'start'];

const FIELD_ORDER = ['title', 'description', 'location', 'tags'];
const PROMPT_NOUNS = {
  title: "each event's title",
  description: `the first ${PROMPT_DESCRIPTION_CHARS} characters of its description`,
  location: 'its location',
  start: 'its start time',
};
const ACTION_PAST = { hide: 'hidden', dim: 'dimmed', highlight: 'highlighted' };

/** "a", "a or b", "a, b or c". */
function orList(words) {
  if (words.length <= 1) return words.join('');
  return words.slice(0, -1).join(', ') + ' or ' + words[words.length - 1];
}

/** "a and b", "a, b and c". */
function andList(words) {
  if (words.length <= 1) return words.join('');
  return words.slice(0, -1).join(', ') + ' and ' + words[words.length - 1];
}

/** The fields a keyword or regex filter matches, as saved (no list: the old default). */
export function filterFields(filter) {
  const list = filter && filter.config && Array.isArray(filter.config.fields) && filter.config.fields.length
    ? filter.config.fields
    : DEFAULT_FIELDS;
  return FIELD_ORDER.filter((f) => list.includes(f));
}

/**
 * Whether an AI prompt filter's action takes the events that fit its
 * description (dim, highlight) or the ones that do not (hide, which keeps
 * what fits). Server: Filters::promptActionApplies.
 */
export function promptTakesFitting(action) {
  return action !== 'hide';
}

/** The label over an AI prompt filter's description, by its action. */
export function promptLabel(action) {
  return action === 'hide' ? 'Keep events that match' : action === 'dim' ? 'Dim events that match' : 'Highlight events that match';
}

/**
 * One sentence, or two for an AI prompt, saying what the filter looks at and
 * what it does with it.
 * @param {{type:string, action:string, config:object}} filter
 * @returns {string[]}
 */
export function filterMatchText(filter) {
  const done = ACTION_PAST[filter.action] || 'changed';
  if (filter.type === 'prompt') {
    const seen = PROMPT_FIELDS.map((f) => PROMPT_NOUNS[f]);
    return [
      `Looks at ${andList(seen)}, and nothing else. Only events on subscribed and Google calendars are judged.`,
      `Events judged ${promptTakesFitting(filter.action) ? 'to fit' : 'not to fit'} the description are ${done}; events not judged yet stay as they are.`,
    ];
  }
  // "their title, description or location"; a tag matches by its name.
  const chosen = filterFields(filter);
  const items = chosen.filter((f) => f !== 'tags').map((f, i) => (i === 0 ? 'their ' + f : f));
  if (chosen.includes('tags')) items.push('the name of one of their tags');
  const where = orList(items);
  if (filter.type === 'regex') {
    return [`Events are ${done} when this pattern finds a match in ${where}, ignoring case.`];
  }
  return [`Events are ${done} when this text appears in ${where}, ignoring case.`];
}
