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
    'delete_event',
    'list_calendars',
    'list_events',
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
