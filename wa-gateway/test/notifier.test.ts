import { pino } from 'pino';
import { describe, expect, it } from 'vitest';
import { Notifier, type NotifierOptions } from '../src/notifier.js';
import { KEY } from './helpers.js';

interface Call {
  url: string;
  headers: Record<string, string>;
  body: string;
}

function notifier(statuses: (number | Error)[], options: Partial<NotifierOptions> = {}): { notifier: Notifier; calls: Call[]; waits: number[] } {
  const calls: Call[] = [];
  const waits: number[] = [];

  const instance = new Notifier({
    url: 'http://127.0.0.1:8000/api/webhooks/wa-gateway',
    secret: 'shh',
    delaysMs: [1_000, 3_000],
    logger: pino({ level: 'silent' }),
    now: () => new Date('2026-10-16T10:00:00Z'),
    wait: async (ms) => {
      waits.push(ms);
    },
    post: async (url, headers, body) => {
      calls.push({ url, headers, body });
      const next = statuses.shift() ?? 200;

      if (next instanceof Error) {
        throw next;
      }

      return next;
    },
    ...options,
  });

  return { notifier: instance, calls, waits };
}

describe('notifier', () => {
  it('posts the event as signed json', async () => {
    const { notifier: sut, calls } = notifier([200]);

    sut.notify({ event: 'session.open', sessionKey: KEY, data: { phone: '+201001234567' } });
    await sut.flush();

    expect(calls).toHaveLength(1);
    const call = calls[0]!;
    expect(JSON.parse(call.body)).toEqual({
      event: 'session.open',
      session_key: KEY,
      data: { phone: '+201001234567' },
      sent_at: '2026-10-16T10:00:00.000Z',
    });
    expect(call.headers['x-gateway-timestamp']).toBe('1792144800');
    expect(call.headers['x-gateway-signature']).toBe(Notifier.sign('shh', '1792144800', call.body));
    expect(call.headers['content-type']).toBe('application/json');
    expect(call.headers.host).toBeUndefined();
  });

  it('signs with hmac sha256 over timestamp and body', () => {
    expect(Notifier.sign('shh', '1', '{}')).toBe('a90673f2895c42e3df468499ca588cea93b36c3f386f71fef268d0d9881e262f');
  });

  it('sends the host header override', async () => {
    const { notifier: sut, calls } = notifier([200], { host: 'hooks.gomal.test' });

    sut.notify({ event: 'gateway.started', sessionKey: null, data: {} });
    await sut.flush();

    expect(calls[0]!.headers.host).toBe('hooks.gomal.test');
  });

  it.each([
    ['server error', 500],
    ['rate limited', 429],
    ['network error', new Error('ECONNREFUSED')],
  ])('retries after a %s', async (_name, failure) => {
    const { notifier: sut, calls, waits } = notifier([failure, 200]);

    sut.notify({ event: 'gateway.started', sessionKey: null, data: {} });
    await sut.flush();

    expect(calls).toHaveLength(2);
    expect(waits).toEqual([1_000]);
  });

  it('gives up after the last retry', async () => {
    const { notifier: sut, calls, waits } = notifier([500, 500, 500, 500]);

    sut.notify({ event: 'gateway.started', sessionKey: null, data: {} });
    await sut.flush();

    expect(calls).toHaveLength(3);
    expect(waits).toEqual([1_000, 3_000]);
  });

  it('does not retry a refused event', async () => {
    const { notifier: sut, calls } = notifier([401]);

    sut.notify({ event: 'gateway.started', sessionKey: null, data: {} });
    await sut.flush();

    expect(calls).toHaveLength(1);
  });

  it('drops events when no webhook url is configured', async () => {
    const { notifier: sut, calls } = notifier([], { url: '' });

    sut.notify({ event: 'gateway.started', sessionKey: null, data: {} });
    await sut.flush();

    expect(calls).toHaveLength(0);
  });
});
