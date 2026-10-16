import { existsSync } from 'node:fs';
import { describe, expect, it, vi } from 'vitest';
import { harness, KEY, markPaired, OTHER_KEY, settle } from './helpers.js';

describe('session manager', () => {
  it('starts a session in its own credentials folder', async () => {
    const h = await harness();

    const view = await h.manager.start(KEY);

    expect(view).toEqual({ state: 'connecting', qr: null, phone: null });
    expect(existsSync(h.store.dir(KEY))).toBe(true);
    expect(h.sockets).toHaveLength(1);
  });

  it('returns the running session instead of starting a second one', async () => {
    const h = await harness();
    await h.manager.start(KEY);
    h.socket().open();
    await settle();

    const view = await h.manager.start(KEY);

    expect(view.state).toBe('open');
    expect(h.sockets).toHaveLength(1);
  });

  it('lesson 7 refuses a session over the cap', async () => {
    const h = await harness({ maxSessions: 1 });
    await h.manager.start(KEY);

    await expect(h.manager.start(OTHER_KEY)).rejects.toMatchObject({ status: 503, code: 'capacity' });
    expect(h.sockets).toHaveLength(1);
  });

  it('forgets a session whose socket could not be created', async () => {
    const h = await harness({
      factory: async () => {
        throw new Error('boom');
      },
    });

    await expect(h.manager.start(KEY)).rejects.toThrow('boom');
    expect(h.manager.stats()).toEqual({ sessions: 0, open: 0 });
  });

  it('reports an unknown session as not found', async () => {
    const h = await harness();

    expect(() => h.manager.view(KEY)).toThrow('not_found');
  });

  it('refuses to send through an unknown session', async () => {
    const h = await harness();

    await expect(h.manager.send(KEY, '+201001234567', 'hi')).rejects.toMatchObject({ code: 'not_open' });
  });

  it('deletes the credentials of a session that is not running', async () => {
    const h = await harness();
    await markPaired(h.store, KEY);

    const result = await h.manager.remove(KEY);

    expect(result).toEqual({ loggedOut: false });
    expect(existsSync(h.store.dir(KEY))).toBe(false);
  });

  it('forgets a session that finished on its own', async () => {
    const h = await harness();
    await markPaired(h.store, KEY);
    await h.manager.start(KEY);
    h.socket().open();

    h.socket().close(401);
    await vi.waitFor(() => expect(h.events).toHaveLength(2));

    expect((await h.manager.list()).live).toEqual([]);
  });

  it('lists live sessions and stored credentials', async () => {
    const h = await harness();
    await markPaired(h.store, OTHER_KEY);
    await h.manager.start(KEY);

    const list = await h.manager.list();

    expect(list.live).toEqual([KEY]);
    expect(list.stored.sort()).toEqual([KEY, OTHER_KEY].sort());
  });

  it('counts live and open sessions', async () => {
    const h = await harness();
    await h.manager.start(KEY);
    await h.manager.start(OTHER_KEY);
    h.socket().open();
    await settle();

    expect(h.manager.stats()).toEqual({ sessions: 2, open: 1 });
  });
});
