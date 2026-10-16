import { existsSync } from 'node:fs';
import { describe, expect, it, vi } from 'vitest';
import { harness, KEY, markPaired, settle } from './helpers.js';

describe('session pairing', () => {
  it('shows the qr code while waiting for the scan', async () => {
    const h = await harness();
    await h.manager.start(KEY);

    h.socket().qr('ref-1');
    await settle();

    expect(h.manager.view(KEY)).toEqual({ state: 'pairing', qr: 'data:image/png;base64,ref-1', phone: null });
  });

  it('opens with the phone of the scanned account and tells laravel', async () => {
    const h = await harness();
    await h.manager.start(KEY);
    h.socket().qr();
    await settle();

    h.socket().open();
    await settle();

    expect(h.manager.view(KEY)).toEqual({ state: 'open', qr: null, phone: '+201001234567' });
    expect(h.events).toEqual([{ event: 'session.open', sessionKey: KEY, data: { phone: '+201001234567' } }]);
  });

  it('reconnects at once when whatsapp asks for a restart after the scan', async () => {
    const h = await harness();
    await h.manager.start(KEY);
    const first = h.socket();

    first.close(515);
    await settle();
    h.socket().open();
    await settle();

    expect(h.sockets).toHaveLength(2);
    expect(h.manager.view(KEY).state).toBe('open');
    expect(h.events.map((event) => event.event)).toEqual(['session.open']);
  });

  it('lesson 3 stops a qr nobody scanned and leaves no files', async () => {
    const h = await harness({ pairingTimeoutMs: 20 });
    await h.manager.start(KEY);
    h.socket().qr();

    await vi.waitFor(() => expect(h.events).toHaveLength(1));

    expect(h.events[0]).toEqual({ event: 'session.closed', sessionKey: KEY, data: { reason: 'qr_expired', fatal: false } });
    expect(h.socket().ended).toBe(true);
    expect(existsSync(h.store.dir(KEY))).toBe(false);
    expect(() => h.manager.view(KEY)).toThrow('not_found');
  });

  it('lesson 3 never retries a session that closes before it was paired', async () => {
    const h = await harness();
    await h.manager.start(KEY);

    h.socket().close(408);
    await vi.waitFor(() => expect(h.events).toHaveLength(1));
    await new Promise((resolve) => setTimeout(resolve, 40));

    expect(h.sockets).toHaveLength(1);
    expect(h.events[0]!.data).toEqual({ reason: 'qr_expired', fatal: false });
  });
});

describe('session drops', () => {
  it.each([
    ['logged out from the phone', 401, 'logged_out'],
    ['banned or forbidden', 403, 'forbidden'],
    ['replaced by another client', 440, 'connection_replaced'],
    ['corrupt credentials', 500, 'bad_session'],
  ])('lesson 4 a fatal drop (%s) purges the credentials and is reported as fatal', async (_name, code, reason) => {
    const h = await harness();
    await markPaired(h.store, KEY);
    await h.manager.start(KEY);
    h.socket().open();

    h.socket().close(code);
    await vi.waitFor(() => expect(h.events).toHaveLength(2));

    expect(h.events[1]).toEqual({ event: 'session.closed', sessionKey: KEY, data: { reason, fatal: true } });
    expect(existsSync(h.store.dir(KEY))).toBe(false);
    expect(h.sockets).toHaveLength(1);
  });

  it('lesson 4 a transient drop of a paired session reconnects after the backoff', async () => {
    const h = await harness();
    await markPaired(h.store, KEY);
    await h.manager.start(KEY);
    h.socket().open();

    h.socket().close(428);
    await vi.waitFor(() => expect(h.manager.view(KEY).state).toBe('connecting'));

    await vi.waitFor(() => expect(h.sockets).toHaveLength(2));
    h.socket().open();
    await settle();

    expect(h.manager.view(KEY).state).toBe('open');
    expect(h.events.filter((event) => event.event === 'session.closed')).toEqual([]);
  });

  it('gives up after the last backoff and keeps the credentials for a later restart', async () => {
    const h = await harness();
    await markPaired(h.store, KEY);
    await h.manager.start(KEY);
    h.socket().open();

    h.socket().close(428);
    await vi.waitFor(() => expect(h.sockets).toHaveLength(2));
    h.socket().close(428);
    await vi.waitFor(() => expect(h.sockets).toHaveLength(3));
    h.socket().close(428);
    await vi.waitFor(() => expect(h.events).toHaveLength(2));

    expect(h.events[1]!.data).toEqual({ reason: 'reconnect_exhausted', fatal: false });
    expect(existsSync(h.store.dir(KEY))).toBe(true);
  });

  it('ignores events from a socket that was replaced', async () => {
    const h = await harness();
    await h.manager.start(KEY);
    const first = h.socket();
    first.close(515);
    await settle();

    first.open();
    await settle();

    expect(h.manager.view(KEY).state).toBe('connecting');
    expect(h.events).toEqual([]);
  });
});

describe('session sending', () => {
  async function openSession() {
    const h = await harness({ sendPerMinute: 2 });
    await h.manager.start(KEY);
    h.socket().open();
    await settle();
    h.socket().onWhatsAppNumbers.add('201112223334');

    return h;
  }

  it('sends a text to the number and returns the whatsapp message id', async () => {
    const h = await openSession();

    const id = await h.manager.send(KEY, '+201112223334', 'رمز التحقق 1234');

    expect(id).toBe('MSG1');
    expect(h.socket().sent).toEqual([{ jid: '201112223334@s.whatsapp.net', text: 'رمز التحقق 1234' }]);
  });

  it('refuses a number that is not on whatsapp', async () => {
    const h = await openSession();

    await expect(h.manager.send(KEY, '+201000000000', 'hi')).rejects.toMatchObject({ status: 422, code: 'not_on_whatsapp' });
    expect(h.socket().sent).toEqual([]);
  });

  it('refuses to send while the session is not open', async () => {
    const h = await harness();
    await h.manager.start(KEY);

    await expect(h.manager.send(KEY, '+201112223334', 'hi')).rejects.toMatchObject({ status: 409, code: 'not_open' });
  });

  it('refuses a send over the per minute limit with the seconds to wait', async () => {
    const h = await openSession();
    await h.manager.send(KEY, '+201112223334', 'one');
    await h.manager.send(KEY, '+201112223334', 'two');

    await expect(h.manager.send(KEY, '+201112223334', 'three')).rejects.toMatchObject({
      status: 429,
      code: 'rate_limited',
      extra: { retry_after: 60 },
    });
  });

  it('reports delivery and read receipts of messages it sent', async () => {
    const h = await openSession();
    const id = await h.manager.send(KEY, '+201112223334', 'hi');

    h.socket().emit('messages.update', [
      { key: { id, fromMe: true }, update: { status: 3 } },
      { key: { id, fromMe: true }, update: { status: 4 } },
    ]);

    expect(h.events.slice(1)).toEqual([
      { event: 'message.status', sessionKey: KEY, data: { id, status: 'delivered' } },
      { event: 'message.status', sessionKey: KEY, data: { id, status: 'read' } },
    ]);
  });

  it('reports a message whatsapp failed to deliver', async () => {
    const h = await openSession();
    const id = await h.manager.send(KEY, '+201112223334', 'hi');

    h.socket().emit('messages.update', [{ key: { id, fromMe: true }, update: { status: 0 } }]);

    expect(h.events.at(-1)).toEqual({ event: 'message.status', sessionKey: KEY, data: { id, status: 'failed' } });
  });

  it('ignores receipts of messages it did not send and pending acks', async () => {
    const h = await openSession();
    const id = await h.manager.send(KEY, '+201112223334', 'hi');

    h.socket().emit('messages.update', [
      { key: { id: 'FROM-PHONE', fromMe: true }, update: { status: 3 } },
      { key: { id, fromMe: false }, update: { status: 3 } },
      { key: { id, fromMe: true }, update: { status: 2 } },
      { key: { id, fromMe: true }, update: {} },
    ]);

    expect(h.events).toHaveLength(1);
  });
});

describe('session logout and stop', () => {
  it('lesson 1 logs the device out of the phone before deleting the credentials', async () => {
    const h = await harness();
    await markPaired(h.store, KEY);
    await h.manager.start(KEY);
    h.socket().open();
    await settle();

    const result = await h.manager.remove(KEY);

    expect(result).toEqual({ loggedOut: true });
    expect(h.socket().loggedOut).toBe(true);
    expect(h.socket().ended).toBe(true);
    expect(existsSync(h.store.dir(KEY))).toBe(false);
    expect(h.events.map((event) => event.event)).toEqual(['session.open']);
  });

  it('still deletes the credentials when the logout call fails, and says so', async () => {
    const h = await harness();
    await markPaired(h.store, KEY);
    await h.manager.start(KEY);
    h.socket().open();
    await settle();
    h.socket().logoutError = new Error('socket closed');

    const result = await h.manager.remove(KEY);

    expect(result).toEqual({ loggedOut: false });
    expect(existsSync(h.store.dir(KEY))).toBe(false);
  });

  it('does not try to log out a session that is not open', async () => {
    const h = await harness();
    await h.manager.start(KEY);

    const result = await h.manager.remove(KEY);

    expect(result).toEqual({ loggedOut: false });
    expect(h.socket().loggedOut).toBe(false);
  });

  it('stops on shutdown keeping the credentials and without reporting a close', async () => {
    const h = await harness();
    await markPaired(h.store, KEY);
    await h.manager.start(KEY);
    h.socket().open();
    await settle();

    h.manager.shutdown();
    h.socket().close(428);
    await settle();

    expect(h.socket().ended).toBe(true);
    expect(existsSync(h.store.dir(KEY))).toBe(true);
    expect(h.events.map((event) => event.event)).toEqual(['session.open']);
    expect(h.manager.stats()).toEqual({ sessions: 0, open: 0 });
  });
});
