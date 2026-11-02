// The reloads owed (docs/design/loading-model.md): one set for the events
// window, the calendar list and the rest. Something they cover changed, or
// their last load failed or didn't apply; every one owed is run together
// whenever the change check reaches the server (changecheck.js), and no
// timer of their own asks again.
//
// A reload owed again while it's running runs once more when that run
// settles, however many times it was owed meanwhile, and a run settles only
// what was owed before it began. Each loader resolves truthy when it
// applied; falsy or a rejection leaves it owed.

let gen = 0;
const owedAt = new Map(); // name -> the generation it was last owed at
const loaders = new Map(); // name -> load()
const running = new Map(); // name -> {run, began}: the run in progress
const again = new Map(); // name -> the one run after it, shared by every ask meanwhile

/** Where a reload's loader lives: the module that holds what it loads. */
export function defineReload(name, load) {
  loaders.set(name, load);
}

/** These reloads are owed: what they cover changed, or their last load didn't land. */
export function owe(...names) {
  const g = ++gen;
  for (const name of names) owedAt.set(name, g);
}

/** Whether it's owed (the tests read this). */
export const isOwed = (name) => owedAt.has(name);

/** The generation now: a load that began here and lands settles what was owed until then. */
export const reloadGeneration = () => gen;

/** A load of `name` that began at `began` (reloadGeneration) landed. */
export function landed(name, began) {
  if ((owedAt.get(name) || 0) <= began) owedAt.delete(name);
}

function start(name) {
  const load = loaders.get(name);
  const began = gen;
  const run = Promise.resolve()
    .then(load)
    .then((r) => { if (r) landed(name, began); return !!r; }, () => false)
    .finally(() => { if (running.get(name)?.run === run) running.delete(name); });
  running.set(name, { run, began });
  return run;
}

/**
 * Run one reload so that what's owed now is covered. Already running since
 * before it was last owed: once more after that, the run every ask
 * meanwhile shares. Resolves true when the run covering this ask landed.
 */
export function reload(name) {
  if (!loaders.has(name)) return Promise.resolve(false);
  const cur = running.get(name);
  if (!cur) return start(name);
  if ((owedAt.get(name) || 0) <= cur.began) return cur.run;
  if (!again.has(name)) {
    again.set(name, cur.run.then(() => {
      again.delete(name);
      const now = running.get(name);
      return now ? now.run : start(name);
    }));
  }
  return again.get(name);
}

/** Every reload owed, together. Resolves once each has settled. */
export function runOwed() {
  return Promise.all([...owedAt.keys()].filter((name) => loaders.has(name)).map(reload));
}
