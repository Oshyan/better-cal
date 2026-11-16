// How a filter's dim or highlight shows on anything drawn for an event: a
// chip, a bar, a block, a band, a context token, a moment on the timeline, a
// holiday's label, a row in the day sheet. The server marks the occurrence
// (`dimmed`, `highlighted`, `highlightColor`); this is the one place that
// turns those marks into the classes and the colour variable the stylesheet
// reads, so every shape takes them by the same rule (#72: context tokens
// once ignored both, and "highlight rain" did nothing visible).

/** ' is-filter-dimmed', ' is-highlighted', both or '' (each with its leading space). */
export function filterClasses(occ) {
  let c = '';
  if (occ && occ.dimmed) c += ' is-filter-dimmed';
  if (occ && occ.highlighted) c += ' is-highlighted';
  return c;
}

/**
 * The highlight's colour as a style declaration ('--hl:#hex'), or '' when
 * the occurrence is not highlighted or its filter chose no colour (the
 * stylesheet then falls back to the accent).
 */
export function hlStyle(occ) {
  return occ && occ.highlighted && occ.highlightColor ? `--hl:${occ.highlightColor}` : '';
}

/**
 * One mark standing for several occurrences (a group of near-duplicates, a
 * day's holidays written as one label, a "+N" for what did not fit): dimmed only when every one is,
 * highlighted when any is, in the colour of the first highlighted one.
 */
export function combinedFilterMarks(occs) {
  const list = occs || [];
  const lit = list.find((m) => m.highlighted);
  return {
    dimmed: list.length > 0 && list.every((m) => !!m.dimmed),
    highlighted: !!lit,
    highlightColor: lit ? lit.highlightColor : undefined,
  };
}

/**
 * A "+N" (or any mark standing for items folded out of sight), as the class
 * suffix and the style its element takes: the combined marks of what it
 * folds, so a highlighted item behind it still shows (#307). style is
 * undefined when there is no colour, so no empty attribute is written.
 */
export function foldedMarks(occs) {
  const m = combinedFilterMarks(occs);
  return { cls: filterClasses(m), style: hlStyle(m) || undefined };
}
