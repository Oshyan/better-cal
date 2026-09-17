#!/usr/bin/env node
// Tests for the Better-Cal MCP server: spawns server.mjs as a child process and
// points it at an in-process mock HTTP server (never the real API), asserting
// JSON-RPC handling, outbound request shape, and response mapping.
//   node tools/mcp/test.mjs

import { spawn } from 'node:child_process';
import { createServer } from 'node:http';
import { createInterface } from 'node:readline';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';
import process from 'node:process';

const SERVER_PATH = join(dirname(fileURLToPath(import.meta.url)), 'server.mjs');
const FAKE_TOKEN = 'bc_' + 'A'.repeat(43);

let pass = 0;
let fail = 0;
function check(name, cond, detail = '') {
  if (cond) {
    pass++;
    return;
  }
  fail++;
  console.log(`FAIL: ${name}${detail !== '' ? ` — ${detail}` : ''}`);
}
function checkEq(name, expected, actual) {
  check(name, JSON.stringify(expected) === JSON.stringify(actual), `expected ${JSON.stringify(expected)}, got ${JSON.stringify(actual)}`);
}

// ---------------------------------------------------------------------------
// Mock Better-Cal HTTP API
// ---------------------------------------------------------------------------

const received = [];
const mock = createServer((req, res) => {
  let raw = '';
  req.on('data', (chunk) => (raw += chunk));
  req.on('end', () => {
    const entry = {
      method: req.method,
      url: req.url,
      headers: req.headers,
      body: raw === '' ? null : JSON.parse(raw),
    };
    received.push(entry);
    const respond = (status, data) => {
      res.writeHead(status, { 'Content-Type': 'application/json; charset=utf-8' });
      res.end(JSON.stringify(data));
    };
    const path = req.url.split('?')[0];
    if (req.method === 'POST' && path === '/api/v1/events') {
      respond(201, {
        instanceId: '42:20260801T190000Z',
        eventId: 42,
        calendarId: entry.body.calendarId,
        title: entry.body.title,
        start: '2026-08-01T19:00:00-07:00',
        end: '2026-08-01T20:00:00-07:00',
        allDay: false,
      });
    } else if (req.method === 'GET' && path === '/api/v1/events') {
      respond(200, { events: [{ instanceId: '42:20260801T190000Z', eventId: 42, title: 'Dinner' }] });
    } else if (req.method === 'GET' && path === '/api/v1/review') {
      respond(200, {
        count: 2,
        items: [
          { key: 'invite_change:12', kind: 'invite_change', status: 'open', title: 'Planning dinner', actions: [
            { name: 'accept', label: 'Accept change', method: 'POST', path: '/review/invite-changes/12/accept' },
            { name: 'dismiss', label: 'Dismiss', method: 'POST', path: '/review/invite-changes/12/dismiss' },
          ] },
          { key: 'rsvp:4410', kind: 'rsvp', status: 'open', title: 'Offsite', actions: [
            { name: 'accepted', label: 'Accept', method: 'POST', path: '/events/4410/rsvp', body: { answer: 'accepted' } },
          ] },
          { key: 'invite_change:9', kind: 'invite_change', status: 'dismissed', title: 'Old', actions: [] },
        ],
      });
    } else if (req.method === 'POST' && (path === '/api/v1/review/invite-changes/12/accept' || path === '/api/v1/events/4410/rsvp')) {
      respond(200, { ok: true });
    } else if (req.method === 'PATCH' && path.startsWith('/api/v1/events/')) {
      respond(200, { eventId: 42, title: entry.body.title ?? 'Dinner' });
    } else if (req.method === 'DELETE' && path.startsWith('/api/v1/events/')) {
      respond(200, { ok: true });
    } else {
      respond(400, { error: { code: 'invalid_request', message: 'mock: unexpected route' } });
    }
  });
});

// ---------------------------------------------------------------------------
// JSON-RPC client for the child process
// ---------------------------------------------------------------------------

let child;
let nextId = 1;
const pending = new Map();

function rpc(method, params) {
  const id = nextId++;
  const p = new Promise((resolve, reject) => {
    pending.set(id, { resolve, reject });
    setTimeout(() => {
      if (pending.delete(id)) reject(new Error(`timeout waiting for response to ${method}`));
    }, 5000);
  });
  child.stdin.write(JSON.stringify({ jsonrpc: '2.0', id, method, params }) + '\n');
  return p;
}

function notify(method, params) {
  child.stdin.write(JSON.stringify({ jsonrpc: '2.0', method, params }) + '\n');
}

async function main() {
  await new Promise((resolve) => mock.listen(0, '127.0.0.1', resolve));
  const port = mock.address().port;

  child = spawn(process.execPath, [SERVER_PATH], {
    env: { ...process.env, BETTERCAL_URL: `http://127.0.0.1:${port}`, BETTERCAL_TOKEN: FAKE_TOKEN },
    stdio: ['pipe', 'pipe', 'inherit'],
  });
  createInterface({ input: child.stdout, terminal: false }).on('line', (line) => {
    if (line.trim() === '') return;
    const msg = JSON.parse(line);
    const waiter = pending.get(msg.id);
    if (waiter) {
      pending.delete(msg.id);
      waiter.resolve(msg);
    }
  });

  // ---- initialize ---------------------------------------------------------
  const init = await rpc('initialize', {
    protocolVersion: '2025-06-18',
    capabilities: {},
    clientInfo: { name: 'test', version: '0.0.0' },
  });
  checkEq('initialize jsonrpc', '2.0', init.jsonrpc);
  check('initialize has protocolVersion', typeof init.result?.protocolVersion === 'string');
  checkEq('initialize serverInfo name', 'better-cal', init.result?.serverInfo?.name);
  check('initialize declares tools capability', typeof init.result?.capabilities?.tools === 'object');
  notify('notifications/initialized', {});

  // ---- tools/list ---------------------------------------------------------
  const list = await rpc('tools/list', {});
  const names = (list.result?.tools ?? []).map((t) => t.name).sort();
  checkEq('tools/list names', [
    'create_event',
    'decide_review',
    'delete_event',
    'list_calendars',
    'list_events',
    'list_review',
    'quick_add',
    'search_events',
    'set_attendance',
    'undo',
    'update_event',
  ], names);
  check('every tool has description and inputSchema', (list.result?.tools ?? []).every(
    (t) => typeof t.description === 'string' && t.inputSchema?.type === 'object'
  ));

  // ---- tools/call create_event: outbound request shape --------------------
  received.length = 0;
  const created = await rpc('tools/call', {
    name: 'create_event',
    arguments: {
      calendarId: 1,
      title: 'Test dinner',
      start: '2026-08-01T19:00:00-07:00',
      end: '2026-08-01T20:00:00-07:00',
      location: 'Zuni',
    },
  });
  checkEq('create_event hit mock once', 1, received.length);
  const reqSeen = received[0];
  checkEq('create_event method', 'POST', reqSeen.method);
  checkEq('create_event path', '/api/v1/events', reqSeen.url);
  checkEq('create_event Authorization header', `Bearer ${FAKE_TOKEN}`, reqSeen.headers.authorization);
  check('create_event content-type json', String(reqSeen.headers['content-type']).includes('application/json'));
  checkEq('create_event body', {
    calendarId: 1,
    title: 'Test dinner',
    start: '2026-08-01T19:00:00-07:00',
    end: '2026-08-01T20:00:00-07:00',
    location: 'Zuni',
  }, reqSeen.body);

  // ---- create_event response mapping --------------------------------------
  check('create_event not isError', created.result?.isError !== true);
  checkEq('create_event content type', 'text', created.result?.content?.[0]?.type);
  const createdPayload = JSON.parse(created.result?.content?.[0]?.text ?? 'null');
  checkEq('create_event maps instanceId', '42:20260801T190000Z', createdPayload?.instanceId);
  checkEq('create_event maps title', 'Test dinner', createdPayload?.title);

  // ---- list_events: query-string shape ------------------------------------
  received.length = 0;
  const listed = await rpc('tools/call', {
    name: 'list_events',
    arguments: { start: '2026-08-01T00:00:00-07:00', end: '2026-08-08T00:00:00-07:00', calendars: [1, 2], q: 'dinner' },
  });
  const listSeen = received[0];
  checkEq('list_events method', 'GET', listSeen.method);
  const url = new URL(listSeen.url, 'http://x');
  checkEq('list_events path', '/api/v1/events', url.pathname);
  checkEq('list_events start param', '2026-08-01T00:00:00-07:00', url.searchParams.get('start'));
  checkEq('list_events end param', '2026-08-08T00:00:00-07:00', url.searchParams.get('end'));
  checkEq('list_events calendars param', '1,2', url.searchParams.get('calendars'));
  checkEq('list_events q param', 'dinner', url.searchParams.get('q'));
  checkEq('list_events auth header', `Bearer ${FAKE_TOKEN}`, listSeen.headers.authorization);
  const listedPayload = JSON.parse(listed.result?.content?.[0]?.text ?? 'null');
  checkEq('list_events maps events array', 1, listedPayload?.events?.length);

  // ---- update_event: declared keys only, numeric path ----------------------
  received.length = 0;
  const updated = await rpc('tools/call', { name: 'update_event', arguments: { id: 42, title: 'Renamed', scope: 'all' } });
  check('update_event not isError', updated.result?.isError !== true && updated.error === undefined);
  checkEq('update_event path', '/api/v1/events/42', received[0]?.url);
  checkEq('update_event body excludes id', { title: 'Renamed', scope: 'all' }, received[0]?.body);

  // ---- BC-03: an event tool must not reach any other route -----------------
  // The id is a path segment and fetch() resolves "../", so a string id would
  // let an event-only delegation PATCH /settings or DELETE a calendar with the
  // PAT attached. Every one of these must be refused before any request leaves.
  received.length = 0;
  for (const [label, name, args] of [
    ['traversal id on update', 'update_event', { id: '../settings', title: 'x' }],
    ['traversal id on delete', 'delete_event', { id: '../calendars/17' }],
    ['traversal id on attendance', 'set_attendance', { id: '42/../../settings', attendance: 'going' }],
    ['numeric-string id', 'delete_event', { id: '42' }],
    ['zero id', 'delete_event', { id: 0 }],
    ['negative id', 'delete_event', { id: -1 }],
    ['fractional id', 'delete_event', { id: 1.5 }],
    ['unsafe-integer id', 'delete_event', { id: 2 ** 53 }],
    ['undeclared key on update', 'update_event', { id: 42, settings: { theme: 'dark' } }],
    ['undeclared key on create', 'create_event', { calendarId: 1, title: 't', start: 's', end: 'e', userId: 2 }],
    ['wrong type', 'update_event', { id: 42, allDay: 'yes' }],
    ['enum violation', 'set_attendance', { id: 42, attendance: 'owner' }],
    ['missing required', 'create_event', { title: 't' }],
    ['non-integer array item', 'list_events', { start: 's', end: 'e', calendars: [1, '2,3&x=1'] }],
    ['arguments not an object', 'undo', ['x']],
  ]) {
    const res = await rpc('tools/call', { name, arguments: args });
    checkEq(`${label}: rejected as invalid params`, -32602, res.error?.code);
  }
  checkEq('invalid arguments never reach the API', 0, received.length);

  // ---- Review queue: read it, and act only through the server's own actions --
  received.length = 0;
  const review = await rpc('tools/call', { name: 'list_review', arguments: {} });
  checkEq('list_review path', '/api/v1/review', received[0]?.url);
  checkEq('list_review maps items', 3, JSON.parse(review.result?.content?.[0]?.text ?? '{}').items?.length);

  received.length = 0;
  const decided = await rpc('tools/call', { name: 'decide_review', arguments: { key: 'invite_change:12', action: 'accept' } });
  check('decide_review accept not isError', decided.result?.isError !== true);
  checkEq('decide_review looks the item up, then posts the SERVER\'s path',
    ['GET /api/v1/review?status=all', 'POST /api/v1/review/invite-changes/12/accept'], received.map((r) => r.method + ' ' + r.url));

  received.length = 0;
  await rpc('tools/call', { name: 'decide_review', arguments: { key: 'rsvp:4410', action: 'accepted' } });
  checkEq('decide_review sends the action\'s own body', { answer: 'accepted' }, received[1]?.body);

  // Nothing the caller types becomes a path: an unknown key or action is an
  // error after the lookup, and no second request is made.
  for (const [label, args] of [
    ['a path as the key', { key: '../settings', action: 'accept' }],
    ['a path as the action', { key: 'invite_change:12', action: '../../calendars/17' }],
    ['an action the item does not offer', { key: 'rsvp:4410', action: 'dismiss' }],
    ['an already decided item', { key: 'invite_change:9', action: 'accept' }],
  ]) {
    received.length = 0;
    const res = await rpc('tools/call', { name: 'decide_review', arguments: args });
    checkEq(`decide_review refuses ${label}`, true, res.result?.isError);
    checkEq(`decide_review ${label}: only the lookup was made`, ['GET'], received.map((r) => r.method));
  }

  // ---- API error maps to isError tool result ------------------------------
  const errored = await rpc('tools/call', { name: 'undo', arguments: {} });
  checkEq('api error sets isError', true, errored.result?.isError);
  check('api error message surfaced', String(errored.result?.content?.[0]?.text ?? '').includes('invalid_request'));

  // ---- unknown tool -> JSON-RPC error -------------------------------------
  const unknown = await rpc('tools/call', { name: 'nope', arguments: {} });
  checkEq('unknown tool error code', -32602, unknown.error?.code);

  // ---- unknown method -> JSON-RPC error -----------------------------------
  const badMethod = await rpc('resources/list', {});
  checkEq('unknown method error code', -32601, badMethod.error?.code);
}

main()
  .catch((err) => {
    fail++;
    console.log(`FAIL: unhandled — ${err?.stack ?? err}`);
  })
  .finally(() => {
    if (child) child.kill();
    mock.close();
    console.log(`\n${pass} passed, ${fail} failed`);
    process.exit(fail === 0 ? 0 : 1);
  });
