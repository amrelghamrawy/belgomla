import { pino } from 'pino';
import { describe, expect, it } from 'vitest';
import { buildServer } from '../src/server.js';
import { harness, KEY, settle } from './helpers.js';

const AUTH = { authorization: 'Bearer secret-token' };

async function server(token = 'secret-token', overrides: Parameters<typeof harness>[0] = {}) {
  const h = await harness(overrides);
  const app = buildServer({ manager: h.manager, token, logger: pino({ level: 'silent' }) });

  return { h, app };
}

describe('authentication', () => {
  it.each([
    ['no header', {}],
    ['wrong token', { authorization: 'Bearer nope' }],
    ['token without scheme', { authorization: 'secret-token' }],
  ])('refuses a request with %s', async (_name, headers) => {
    const { app } = await server();

    const response = await app.inject({ method: 'GET', url: '/sessions', headers });

    expect(response.statusCode).toBe(401);
    expect(response.json()).toEqual({ error: 'unauthenticated' });
  });

  it('refuses every request when no token is configured', async () => {
    const { app } = await server('');

    const response = await app.inject({ method: 'GET', url: '/sessions', headers: { authorization: 'Bearer ' } });

    expect(response.statusCode).toBe(401);
  });

  it('serves health without a token', async () => {
    const { app } = await server();

    const response = await app.inject({ method: 'GET', url: '/health' });

    expect(response.statusCode).toBe(200);
    expect(response.json()).toEqual({ ok: true, sessions: 0, open: 0 });
  });
});

describe('sessions api', () => {
  it('starts a session', async () => {
    const { app } = await server();

    const response = await app.inject({ method: 'POST', url: `/sessions/${KEY}`, headers: AUTH });

    expect(response.statusCode).toBe(200);
    expect(response.json()).toEqual({ state: 'connecting', qr: null, phone: null });
  });

  it('shows the qr of a pairing session', async () => {
    const { h, app } = await server();
    await h.manager.start(KEY);
    h.socket().qr('ref-9');
    await settle();

    const response = await app.inject({ method: 'GET', url: `/sessions/${KEY}`, headers: AUTH });

    expect(response.json()).toEqual({ state: 'pairing', qr: 'data:image/png;base64,ref-9', phone: null });
  });

  it.each([
    ['unknown session', KEY],
    ['key that is not a uuid', 'tenant-1'],
  ])('answers 404 for an %s', async (_name, key) => {
    const { app } = await server();

    const response = await app.inject({ method: 'GET', url: `/sessions/${key}`, headers: AUTH });

    expect(response.statusCode).toBe(404);
    expect(response.json()).toEqual({ error: 'not_found' });
  });

  it('answers 503 when the session cap is reached', async () => {
    const { app } = await server('secret-token', { maxSessions: 0 });

    const response = await app.inject({ method: 'POST', url: `/sessions/${KEY}`, headers: AUTH });

    expect(response.statusCode).toBe(503);
    expect(response.json()).toEqual({ error: 'capacity' });
  });

  it('removes a session and says whether the phone was told', async () => {
    const { h, app } = await server();
    await h.manager.start(KEY);
    h.socket().open();
    await settle();

    const response = await app.inject({ method: 'DELETE', url: `/sessions/${KEY}`, headers: AUTH });

    expect(response.statusCode).toBe(200);
    expect(response.json()).toEqual({ logged_out: true });
  });

  it('lists live and stored sessions', async () => {
    const { h, app } = await server();
    await h.manager.start(KEY);

    const response = await app.inject({ method: 'GET', url: '/sessions', headers: AUTH });

    expect(response.json()).toEqual({ live: [KEY], stored: [KEY] });
  });

  it('answers 404 json for an unknown route', async () => {
    const { app } = await server();

    const response = await app.inject({ method: 'GET', url: '/nope', headers: AUTH });

    expect(response.statusCode).toBe(404);
    expect(response.json()).toEqual({ error: 'not_found' });
  });

  it('hides unexpected errors behind server_error', async () => {
    const { app } = await server('secret-token', {
      factory: async () => {
        throw new Error('internal detail');
      },
    });

    const response = await app.inject({ method: 'POST', url: `/sessions/${KEY}`, headers: AUTH });

    expect(response.statusCode).toBe(500);
    expect(response.json()).toEqual({ error: 'server_error' });
  });
});

describe('messages api', () => {
  async function openServer() {
    const { h, app } = await server('secret-token', { sendPerMinute: 1 });
    await h.manager.start(KEY);
    h.socket().open();
    await settle();
    h.socket().onWhatsAppNumbers.add('201112223334');

    return { h, app };
  }

  const send = (body: Record<string, unknown>) => ({ method: 'POST' as const, url: `/sessions/${KEY}/messages`, headers: AUTH, payload: body });

  it('sends a message', async () => {
    const { app } = await openServer();

    const response = await app.inject(send({ to: '+201112223334', text: 'hello' }));

    expect(response.statusCode).toBe(200);
    expect(response.json()).toEqual({ id: 'MSG1' });
  });

  it.each([
    ['missing text', { to: '+201112223334' }],
    ['empty text', { to: '+201112223334', text: '' }],
    ['local phone format', { to: '01112223334', text: 'hi' }],
    ['unknown field', { to: '+201112223334', text: 'hi', media: 'x' }],
    ['text too long', { to: '+201112223334', text: 'x'.repeat(4097) }],
  ])('refuses an invalid body (%s)', async (_name, body) => {
    const { app } = await openServer();

    const response = await app.inject(send(body));

    expect(response.statusCode).toBe(400);
    expect(response.json()).toEqual({ error: 'validation_failed' });
  });

  it('answers 422 for a number that is not on whatsapp', async () => {
    const { app } = await openServer();

    const response = await app.inject(send({ to: '+201000000000', text: 'hi' }));

    expect(response.statusCode).toBe(422);
    expect(response.json()).toEqual({ error: 'not_on_whatsapp' });
  });

  it('answers 409 when the session is not open', async () => {
    const { app } = await server();

    const response = await app.inject(send({ to: '+201112223334', text: 'hi' }));

    expect(response.statusCode).toBe(409);
    expect(response.json()).toEqual({ error: 'not_open' });
  });

  it('answers 429 with retry_after over the send limit', async () => {
    const { app } = await openServer();
    await app.inject(send({ to: '+201112223334', text: 'one' }));

    const response = await app.inject(send({ to: '+201112223334', text: 'two' }));

    expect(response.statusCode).toBe(429);
    expect(response.json()).toEqual({ error: 'rate_limited', retry_after: 60 });
  });
});
