// Static source checks over the whole frontend tree. These catch the class of
// break that unit tests structurally cannot: a reference that only explodes
// when a particular branch renders (an icon in a drawer nobody opened during
// the test run). Run: node web/tests/static.mjs
//
// 1. Every module parses as a real ES module.
// 2. Every <${Component}> referenced in an htm template is in scope.
//
// Both exist because each has already shipped a live break: a stray import
// inserted inside a multi-line import statement (blank app), and an <${Icon}>
// used in files that imported some OTHER icon (dead "+ New" button, frozen
// agenda).

import { readFileSync, readdirSync, statSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import vm from 'node:vm';
import { spawnSync } from 'node:child_process';

const root = join(dirname(fileURLToPath(import.meta.url)), '..', 'src');

function walk(dir, out = []) {
  for (const name of readdirSync(dir)) {
    const p = join(dir, name);
    if (statSync(p).isDirectory()) walk(p, out);
    else if (name.endsWith('.js')) out.push(p);
  }
  return out;
}

let passed = 0;
let failed = 0;
const fail = (msg) => { failed++; console.log('FAIL: ' + msg); };

const files = walk(root);

// --- 1. every module parses ---------------------------------------------
for (const file of files) {
  try {
    new vm.SourceTextModule(readFileSync(file, 'utf8'), { identifier: file });
    passed++;
  } catch (e) {
    fail('syntax in ' + file + ': ' + e.message);
  }
}

// --- 2. every templated component is in scope ---------------------------
for (const file of files) {
  const src = readFileSync(file, 'utf8');
  const used = new Set();
  for (const m of src.matchAll(/<\$\{([A-Za-z_$][\w$]*)\}/g)) used.add(m[1]);
  if (used.size === 0) continue;

  const scope = new Set();
  for (const m of src.matchAll(/^import\s+\{([^}]*)\}\s+from/gms)) {
    for (const part of m[1].split(',')) {
      const name = part.trim().split(/\s+as\s+/).pop().trim();
      if (name) scope.add(name);
    }
  }
  for (const m of src.matchAll(/^import\s+([A-Za-z_$][\w$]*)\s+from/gm)) scope.add(m[1]);
  for (const m of src.matchAll(/(?:function|const|let|var|class)\s+([A-Za-z_$][\w$]*)/g)) scope.add(m[1]);

  for (const name of used) {
    if (scope.has(name)) passed++;
    else fail('<' + name + '> used but not in scope in ' + file);
  }
}

// --- 3. deep-link handoff never reaches a request line ---------------------
// (BC-18) The share target must POST, the worker must answer that POST, and
// the shell's head script must clear the address before the first <link>.
{
  const web = join(root, '..');
  const manifest = JSON.parse(readFileSync(join(web, 'manifest.webmanifest'), 'utf8'));
  const check = (ok, msg) => { if (ok) passed++; else fail(msg); };
  check(manifest.share_target && manifest.share_target.method === 'POST', 'manifest share_target must be POST');
  const sw = readFileSync(join(web, 'sw.js'), 'utf8');
  check(/method === 'POST' && url\.pathname === '\/share'/.test(sw), 'sw.js must handle POST /share');
  const html = readFileSync(join(web, 'index.html'), 'utf8');
  const cleanup = html.indexOf("sessionStorage.setItem('bc-handoff'");
  const firstLink = html.indexOf('<link');
  check(cleanup > 0 && firstLink > cleanup, 'index.html handoff script must run before the first <link>');
  check(/history\.replaceState\(null,'','\/'\)/.test(html), 'index.html handoff script must rewrite the address to /');
  check(/<meta name="referrer" content="strict-origin-when-cross-origin">/.test(html), 'index.html must set a referrer policy');
}

// --- 4. repeated geocoder failures reach the owner ------------------------
// Transport failures stay out of the location-picker response, so the boot
// health notice is the user-visible half of the server health streak.
{
  const web = join(root, '..');
  const system = readFileSync(join(root, 'app', 'system.js'), 'utf8');
  const check = (ok, msg) => { if (ok) passed++; else fail(msg); };
  check(/subject\.startsWith\('geocoder:'\)[\s\S]*consecutiveFailures >= 3/.test(system),
    'system health must announce geocoder failures only after a three-failure streak');
  check(system.includes('See Settings, System.'),
    'geocoder failure notice must direct the owner to the health details');
}

// --- 5. calendar warnings lead to the decision surface --------------------
// A warning triangle used to show a help cursor but did nothing. Keep the
// sidebar affordance actionable, make touch long-press stay on that button,
// and keep paused subscriptions represented in the Review inbox.
{
  const sidebar = readFileSync(join(root, 'app', 'Sidebar.js'), 'utf8');
  const review = readFileSync(join(root, 'app', 'ReviewPage.js'), 'utf8');
  const settings = readFileSync(join(root, 'app', 'SettingsPage.js'), 'utf8');
  const check = (ok, msg) => { if (ok) passed++; else fail(msg); };
  check(/function healthBadge\(cal, onOpen\)[\s\S]*?<button[\s\S]*?onClick=\$\{\(e\) => \{ e\.stopPropagation\(\); onOpen\(\); \}\}/.test(sidebar),
    'calendar health warning must be a button that opens calendar options');
  check(/class="bc-health"[\s\S]*?onPointerDown=.*?stopPropagation[\s\S]*?onContextMenu=.*?preventDefault.*?stopPropagation/.test(sidebar),
    'calendar health warning must not trigger the phone row long-press menu');
  check(review.includes("item.kind === 'subscription'") && review.includes("manageCal: d.calendarId") && review.includes('Calendar options'),
    'paused subscriptions in Review must open their calendar options');
  check(review.includes("invite_new: 'New emailed invitation'") && review.includes('Invitation added. You have not replied.'),
    'first-time emailed invitations must be visibly distinct and must not imply that adding one sent an RSVP');
  check(review.includes("item.kind === 'invite_new'") && review.includes("'Dismissed. Nothing was added.'"),
    'dismissing a first-time emailed invitation must say that no event was added');
  check(settings.includes('subscriptionsPaused ? loadReviewCount()'),
    'revoking a key that pauses subscriptions must refresh the Review badge');
}

// --- 6. proposal decisions stay bound to what Review displayed -------------
// A card must disclose its resolved calendar, echo its exact revision token,
// and refresh rather than retrying silently if the proposal changed.
{
  const proposal = readFileSync(join(root, 'app', 'ProposalCard.js'), 'utf8');
  const reviewController = readFileSync(join(root, '..', '..', 'server', 'src', 'Http', 'Controllers', 'ReviewController.php'), 'utf8');
  const check = (ok, msg) => { if (ok) passed++; else fail(msg); };
  check(proposal.includes('Destination calendar') && proposal.includes('destinations.events?.[i]'),
    'proposal cards must show each resolved destination calendar');
  check(proposal.includes('{ reviewToken: p.reviewToken }') && proposal.includes("e.code === 'proposal_changed'"),
    'proposal decisions must echo the reviewed token and refresh changed cards');
  check(proposal.includes('disabled=${busy || !p.acceptAllowed}') && proposal.includes('bc-proposal-target-error'),
    'proposal cards must disable acceptance and explain an unavailable target');
  check(reviewController.includes("['reviewToken' => $p['reviewToken']]")
      && reviewController.includes("$p['acceptAllowed'] ? self::action("),
    'Review proposal actions must carry the displayed token and omit unsafe acceptance');
}

// --- 7. sign-out and system health use opaque push identities --------------
// Sign-out stops reminders at the server but deliberately keeps the browser's
// PushManager subscription so signing in can quietly restore it. Health needs
// only a hash to recognise this device; a raw endpoint is a delivery capability.
{
  const api = readFileSync(join(root, 'app', 'api.js'), 'utf8');
  const settings = readFileSync(join(root, 'app', 'SettingsPage.js'), 'utf8');
  const push = readFileSync(join(root, 'app', 'push.js'), 'utf8');
  const system = readFileSync(join(root, 'app', 'system.js'), 'utf8');
  const check = (ok, msg) => { if (ok) passed++; else fail(msg); };
  check(/function logout\(pushEndpointHash = null\)[\s\S]*?body: pushEndpointHash \? \{ pushEndpointHash \} : \{\}/.test(api),
    'logout API must send the current push endpoint hash when available');
  check(settings.includes('logout(await currentEndpointHash())'),
    'Settings sign-out must identify this browser push destination');
  check(/function currentPushEndpoint\(\)[\s\S]*?serviceWorker\.getRegistration\(\)/.test(push)
      && !/function currentPushEndpoint\(\)[\s\S]*?serviceWorker\.ready[\s\S]*?^}/m.test(push),
    'reading the current push endpoint must not hang on serviceWorker.ready');
  check(system.includes('currentEndpointHash') && system.includes('r.endpointHash === endpointHash') && !system.includes('r.endpoint === endpoint'),
    'system health must match this device by an endpoint hash, not the raw endpoint');
}

// --- 8. stored event data cannot set unbounded client loop/navigation work --
{
  const reschedule = readFileSync(join(root, 'ui', 'RescheduleMode.js'), 'utf8');
  const sheet = readFileSync(join(root, 'app', 'EventSheetBody.js'), 'utf8');
  const check = (ok, msg) => { if (ok) passed++; else fail(msg); };
  check(reschedule.includes("scope.querySelectorAll('[data-day]')") && !reschedule.includes('i <= spanDays'),
    'reschedule highlighting must walk rendered cells, not every stored event day');
  check(sheet.includes('const sourceUrl = safeWebUrl(occ.url)') && sheet.includes("window.open(sourceUrl, '_blank', 'noopener')")
      && sheet.includes('href=${sourceUrl}') && !sheet.includes("window.open(occ.url") && !sheet.includes('href=${occ.url}'),
    'event source navigation must revalidate legacy stored URLs at the click sink');
}

// --- 9. login and explicit sign-out local privacy --------------------------
{
  const login = readFileSync(join(root, 'app', 'Login.js'), 'utf8');
  const api = readFileSync(join(root, 'app', 'api.js'), 'utf8');
  const resume = readFileSync(join(root, 'app', 'resume-storage.js'), 'utf8');
  const check = (ok, msg) => { if (ok) passed++; else fail(msg); };
  check(login.includes('useState(false)') && login.includes("autocomplete=\"current-password\"")
      && login.includes("shown ? 'text' : 'password'"),
    'login password must start masked while retaining autofill and an explicit reveal');
  check(api.includes("res.status === 401") && api.includes('clearResume()')
      && /function logout[\s\S]*finally[\s\S]*clearResume\(\)/.test(api),
    '401 and explicit logout must clear saved resume context');
  check(resume.includes("['localStorage', 'sessionStorage']") && resume.includes('globalThis[name]'),
    'resume clearing must cover both installed-app and tab storage');
}

// --- 10a. the shell document's inline scripts parse --------------------------
// They run before any module (theme, deep-link handoff, the start record and
// its load-failure message); a syntax error there is silent and total.
{
  const doc = readFileSync(join(root, '..', 'index.html'), 'utf8');
  for (const [i, m] of [...doc.matchAll(/<script>([\s\S]*?)<\/script>/g)].entries()) {
    try { new Function(m[1]); passed++; } catch (e) { fail('index.html inline script ' + i + ': ' + e.message); }
  }
}

// --- 10. the offline cache holds every module the app starts with ---------
// The server lists the app's static import graph (server/src/Http/AppShell.php)
// for the service worker to cache and the page to preload. A module it misses
// is fetched from the network on every start, so a stalled download on a weak
// connection hangs the app on a white screen. Its parser once missed imports
// written across lines. Compare it with V8's own reading of the same files.
{
  const webRoot = join(root, '..');
  const want = new Set();
  const queue = ['src/app/main.js'];
  while (queue.length) {
    const rel = queue.shift();
    if (want.has(rel)) continue;
    want.add(rel);
    const mod = new vm.SourceTextModule(readFileSync(join(webRoot, rel), 'utf8'), { identifier: rel });
    for (const spec of mod.dependencySpecifiers) {
      if (spec.startsWith('.')) queue.push(join(dirname(rel), spec).split('\\').join('/'));
    }
  }
  const php = spawnSync('php', ['-r', 'require $argv[1]; echo json_encode((new BetterCal\\Http\\AppShell($argv[2]))->graph());',
    join(webRoot, '..', 'server', 'src', 'bootstrap.php'), webRoot], { encoding: 'utf8' });
  if (php.error && php.error.code === 'ENOENT') {
    console.log('SKIP: php not found, so the offline-cache module list was not compared');
  } else {
    let have = null;
    try { have = new Set(JSON.parse(php.stdout)); } catch { fail('AppShell graph did not return JSON: ' + (php.stderr || php.stdout).slice(0, 300)); }
    if (have) {
      const missing = [...want].filter((m) => !have.has(m));
      const extra = [...have].filter((m) => !want.has(m));
      if (missing.length || extra.length) {
        fail('offline cache module list differs from the real import graph: missing ' + JSON.stringify(missing) + ', extra ' + JSON.stringify(extra));
      } else {
        passed++;
      }
    }
  }
}

console.log('');
console.log(passed + ' checks passed, ' + failed + ' failed (' + files.length + ' modules)');
if (failed > 0) process.exit(1);
