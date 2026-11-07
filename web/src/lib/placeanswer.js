// What the place dropdown shows for one GET /geocode/search answer: the
// rows, or, when there are none and the server says why ("No place matches
// all of those words."), that one sentence in place of the list. Without
// rows or a reason (a server that couldn't reach any service, or an older
// one) the dropdown stays closed. Pure.
export function placeAnswer(data) {
  const results = (data && Array.isArray(data.results)) ? data.results : [];
  const credits = (data && Array.isArray(data.credits)) ? data.credits : [];
  const note = results.length === 0 && data && typeof data.reason === 'string' && data.reason ? data.reason : '';
  return { results, credits: results.length ? credits : [], note };
}
