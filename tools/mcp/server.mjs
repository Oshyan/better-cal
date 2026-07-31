#!/usr/bin/env node
// Better-Cal MCP server: newline-delimited JSON-RPC 2.0 over stdio, zero dependencies.
// Requires Node >= 20. Env: BETTERCAL_URL (e.g. https://cal.example.com) and
// BETTERCAL_TOKEN (a "bc_..." personal access token; see server/bin/token.php).

import { createInterface } from 'node:readline';
import process from 'node:process';

const BASE_URL = (process.env.BETTERCAL_URL ?? '').replace(/\/+$/, '');
const TOKEN = process.env.BETTERCAL_TOKEN ?? '';

const PROTOCOL_VERSION = '2025-06-18';
const SERVER_INFO = { name: 'better-cal', version: '0.1.0' };

// ---------------------------------------------------------------------------
// Better-Cal REST client
// ---------------------------------------------------------------------------

async function api(method, path, { query, body } = {}) {
  if (!BASE_URL) throw new Error('BETTERCAL_URL environment variable is not set');
  if (!TOKEN) throw new Error('BETTERCAL_TOKEN environment variable is not set');
  let url = `${BASE_URL}/api/v1${path}`;
  if (query) {
    const qs = new URLSearchParams();
    for (const [k, v] of Object.entries(query)) {
      if (v !== undefined && v !== null && v !== '') qs.set(k, String(v));
    }
    const s = qs.toString();
    if (s) url += `?${s}`;
  }
  const headers = { Authorization: `Bearer ${TOKEN}`, Accept: 'application/json' };
  if (body !== undefined) headers['Content-Type'] = 'application/json';
  const res = await fetch(url, {
    method,
    headers,
    body: body !== undefined ? JSON.stringify(body) : undefined,
  });
  const text = await res.text();
  let data = null;
  try {
    data = text === '' ? null : JSON.parse(text);
  } catch {
    data = { raw: text };
  }
  if (!res.ok) {
    const code = data?.error?.code ?? 'http_error';
    const message = data?.error?.message ?? `HTTP ${res.status}`;
    throw new Error(`Better-Cal API error ${res.status} (${code}): ${message}`);
  }
  return data;
}

function pick(obj, keys) {
  const out = {};
  for (const k of keys) {
    if (obj[k] !== undefined) out[k] = obj[k];
  }
  return out;
}

// ---------------------------------------------------------------------------
// Tools
// ---------------------------------------------------------------------------

const ISO = 'ISO8601 datetime with offset, e.g. 2026-08-01T19:00:00-07:00';
const SCOPE = {
  type: 'string',
  enum: ['this', 'following', 'all'],
  description: 'Required when the event is recurring: apply to this instance, this and following, or all instances.',
};
const INSTANCE_START = {
  type: 'string',
  description: `Occurrence start (${ISO}) identifying which instance, for scope "this"/"following". Use the occurrence's "start" field from list_events.`,
};

const TOOLS = [
  {
    name: 'list_events',
    description: 'List expanded event occurrences in a time window. Recurring events arrive pre-expanded, one entry per occurrence.',
    inputSchema: {
      type: 'object',
      properties: {
        start: { type: 'string', description: `Window start (${ISO})` },
        end: { type: 'string', description: `Window end (${ISO})` },
        calendars: { type: 'array', items: { type: 'integer' }, description: 'Restrict to these calendar ids (default all)' },
        q: { type: 'string', description: 'Optional text filter applied within the window' },
      },
      required: ['start', 'end'],
    },
    handler: (a) =>
      api('GET', '/events', {
        query: {
          start: a.start,
          end: a.end,
          calendars: Array.isArray(a.calendars) ? a.calendars.join(',') : a.calendars,
          q: a.q,
        },
      }),
  },
  {
    name: 'search_events',
    description: 'Full-text search across all events (past included), ranked, newest window first.',
    inputSchema: {
      type: 'object',
      properties: {
        q: { type: 'string', description: 'Search text' },
        limit: { type: 'integer', description: 'Max results (default 50)' },
      },
      required: ['q'],
    },
    handler: (a) => api('GET', '/search', { query: { q: a.q, limit: a.limit } }),
  },
  {
    name: 'create_event',
    description: 'Create an event. Returns the created occurrence (first instance when recurring).',
    inputSchema: {
      type: 'object',
      properties: {
        calendarId: { type: 'integer' },
        title: { type: 'string' },
        start: { type: 'string', description: ISO },
        end: { type: 'string', description: ISO },
        allDay: { type: 'boolean' },
        location: { type: 'string' },
        description: { type: 'string' },
        rrule: { type: 'string', description: 'iCal RRULE, e.g. FREQ=WEEKLY;BYDAY=TU' },
        tzid: { type: 'string', description: 'IANA timezone for recurrence math, e.g. America/Los_Angeles' },
        url: { type: 'string' },
      },
      required: ['calendarId', 'title', 'start', 'end'],
    },
    handler: (a) =>
      api('POST', '/events', {
        body: pick(a, ['calendarId', 'title', 'start', 'end', 'allDay', 'location', 'description', 'rrule', 'tzid', 'url']),
      }),
  },
  {
    name: 'quick_add',
    description: 'Natural-language event creation ("Dinner with Sam next thursday 7pm at Zuni"). Returns a parsed draft; set commit=true to actually create the event.',
    inputSchema: {
      type: 'object',
      properties: {
        text: { type: 'string', description: 'Natural language description of the event' },
        commit: { type: 'boolean', description: 'Create the event (default false: parse only, returns draft)' },
        calendarId: { type: 'integer', description: 'Target calendar (default: first calendar)' },
        tz: { type: 'string', description: 'IANA timezone for interpreting relative dates (default: this machine’s timezone)' },
      },
      required: ['text'],
    },
    handler: (a) =>
      api('POST', '/quickadd', {
        body: {
          text: a.text,
          tz: a.tz ?? Intl.DateTimeFormat().resolvedOptions().timeZone,
          commit: a.commit ?? false,
          ...(a.calendarId !== undefined ? { calendarId: a.calendarId } : {}),
        },
      }),
  },
  {
    name: 'update_event',
    description: 'Patch fields on an event by numeric eventId. For recurring events pass scope (and instanceStart for scope "this"/"following").',
    inputSchema: {
      type: 'object',
      properties: {
        id: { type: 'integer', description: 'Numeric eventId (from an occurrence, not the instanceId)' },
        title: { type: 'string' },
        start: { type: 'string', description: ISO },
        end: { type: 'string', description: ISO },
        allDay: { type: 'boolean' },
        location: { type: 'string' },
        description: { type: 'string' },
        rrule: { type: 'string' },
        calendarId: { type: 'integer' },
        tzid: { type: 'string' },
        url: { type: 'string' },
        scope: SCOPE,
        instanceStart: INSTANCE_START,
      },
      required: ['id'],
    },
    handler: (a) => {
      const { id, ...patch } = a;
      return api('PATCH', `/events/${id}`, { body: patch });
    },
  },
  {
    name: 'delete_event',
    description: 'Delete an event by numeric eventId. For recurring events pass scope (and instanceStart for scope "this"/"following").',
    inputSchema: {
      type: 'object',
      properties: {
        id: { type: 'integer' },
        scope: SCOPE,
        instanceStart: INSTANCE_START,
      },
      required: ['id'],
    },
    handler: (a) => api('DELETE', `/events/${a.id}`, { body: pick(a, ['scope', 'instanceStart']) }),
  },
  {
    name: 'set_attendance',
    description: 'Set attendance on an event (works on read-only subscribed-feed events too).',
    inputSchema: {
      type: 'object',
      properties: {
        id: { type: 'integer' },
        attendance: { type: 'string', enum: ['none', 'interested', 'going', 'hidden'] },
      },
      required: ['id', 'attendance'],
    },
    handler: (a) => api('POST', `/events/${a.id}/attendance`, { body: { attendance: a.attendance } }),
  },
  {
    name: 'list_calendars',
    description: 'List calendars (with colors, kind, visibility, feed health), folders, and tags.',
    inputSchema: { type: 'object', properties: {} },
    handler: () => api('GET', '/calendars'),
  },
  {
    name: 'undo',
    description: 'Undo the most recent mutating change (create/update/delete) made by this user.',
    inputSchema: { type: 'object', properties: {} },
    handler: () => api('POST', '/undo', { body: {} }),
  },
];

const TOOLS_BY_NAME = new Map(TOOLS.map((t) => [t.name, t]));

// ---------------------------------------------------------------------------
// JSON-RPC 2.0 over newline-delimited stdio
// ---------------------------------------------------------------------------

function send(msg) {
  process.stdout.write(JSON.stringify(msg) + '\n');
}

function reply(id, result) {
  send({ jsonrpc: '2.0', id, result });
}

function replyError(id, code, message) {
  send({ jsonrpc: '2.0', id, error: { code, message } });
}

async function handleRequest(msg) {
  const { id, method, params } = msg;
  switch (method) {
    case 'initialize':
      reply(id, {
        protocolVersion: PROTOCOL_VERSION,
        capabilities: { tools: {} },
        serverInfo: SERVER_INFO,
      });
      return;
    case 'ping':
      reply(id, {});
      return;
    case 'tools/list':
      reply(id, {
        tools: TOOLS.map(({ name, description, inputSchema }) => ({ name, description, inputSchema })),
      });
      return;
    case 'tools/call': {
      const tool = TOOLS_BY_NAME.get(params?.name);
      if (!tool) {
        replyError(id, -32602, `Unknown tool: ${params?.name}`);
        return;
      }
      try {
        const result = await tool.handler(params?.arguments ?? {});
        reply(id, { content: [{ type: 'text', text: JSON.stringify(result, null, 2) }] });
      } catch (err) {
        reply(id, { content: [{ type: 'text', text: String(err?.message ?? err) }], isError: true });
      }
      return;
    }
    default:
      replyError(id, -32601, `Method not found: ${method}`);
  }
}

const rl = createInterface({ input: process.stdin, terminal: false });
rl.on('line', (line) => {
  line = line.trim();
  if (line === '') return;
  let msg;
  try {
    msg = JSON.parse(line);
  } catch {
    replyError(null, -32700, 'Parse error');
    return;
  }
  if (msg.id === undefined || msg.id === null) {
    // Notification (e.g. notifications/initialized): nothing to do.
    return;
  }
  handleRequest(msg).catch((err) => replyError(msg.id, -32603, String(err?.message ?? err)));
});
rl.on('close', () => process.exit(0));
