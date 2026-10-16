import { existsSync } from 'node:fs';
import { mkdir, mkdtemp, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { beforeEach, describe, expect, it } from 'vitest';
import { AuthStore } from '../src/auth-store.js';
import { KEY, markPaired, OTHER_KEY } from './helpers.js';

describe('auth store', () => {
  let root: string;
  let store: AuthStore;

  beforeEach(async () => {
    root = await mkdtemp(join(tmpdir(), 'wa-store-'));
    store = new AuthStore(root);
  });

  it.each([['path traversal', '../etc'], ['not a uuid', 'tenant-1'], ['upper case uuid', KEY.toUpperCase()]])(
    'refuses a key that is not a lower case uuid (%s)',
    (_name, key) => {
      expect(() => store.dir(key)).toThrow('invalid session key');
    },
  );

  it('creates the session folder', async () => {
    const dir = await store.ensure(KEY);

    expect(dir).toBe(join(root, KEY));
    expect(existsSync(dir)).toBe(true);
  });

  it('knows a session is paired once the credentials name the account', async () => {
    await markPaired(store, KEY);

    expect(await store.isPaired(KEY)).toBe(true);
  });

  it('treats missing, unpaired or corrupt credentials as not paired', async () => {
    expect(await store.isPaired(KEY)).toBe(false);

    await store.ensure(KEY);
    await writeFile(join(store.dir(KEY), 'creds.json'), JSON.stringify({ registered: false }));
    expect(await store.isPaired(KEY)).toBe(false);

    await writeFile(join(store.dir(KEY), 'creds.json'), '{not json');
    expect(await store.isPaired(KEY)).toBe(false);
  });

  it('lists only session folders', async () => {
    await store.ensure(KEY);
    await store.ensure(OTHER_KEY);
    await mkdir(join(root, 'not-a-session'));
    await writeFile(join(root, 'stray.txt'), 'x');

    expect((await store.list()).sort()).toEqual([KEY, OTHER_KEY].sort());
  });

  it('lists nothing when the root does not exist yet', async () => {
    expect(await new AuthStore(join(root, 'missing')).list()).toEqual([]);
  });

  it('purges a session folder and tolerates one that is already gone', async () => {
    await markPaired(store, KEY);

    await store.purge(KEY);
    await store.purge(KEY);

    expect(existsSync(store.dir(KEY))).toBe(false);
  });
});
