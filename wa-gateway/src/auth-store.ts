import { mkdir, readdir, readFile, rm } from 'node:fs/promises';
import { join } from 'node:path';

export const SESSION_KEY = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/;

/**
 * One folder of WhatsApp Web credentials per session key, readable only by the service user. Keys are
 * UUIDs (validated before any path is built), so a key can never point outside the root.
 */
export class AuthStore {
  constructor(private readonly root: string) {}

  dir(key: string): string {
    if (!SESSION_KEY.test(key)) {
      throw new Error(`invalid session key: ${key}`);
    }

    return join(this.root, key);
  }

  async ensure(key: string): Promise<string> {
    const dir = this.dir(key);
    await mkdir(dir, { recursive: true, mode: 0o700 });

    return dir;
  }

  /** The session finished a QR scan once: Baileys writes the account into `creds.json` as `me`. */
  async isPaired(key: string): Promise<boolean> {
    try {
      const creds = JSON.parse(await readFile(join(this.dir(key), 'creds.json'), 'utf8')) as { me?: { id?: unknown } };

      return typeof creds.me?.id === 'string';
    } catch {
      return false;
    }
  }

  async list(): Promise<string[]> {
    try {
      const entries = await readdir(this.root, { withFileTypes: true });

      return entries.filter((entry) => entry.isDirectory() && SESSION_KEY.test(entry.name)).map((entry) => entry.name);
    } catch {
      return [];
    }
  }

  /** Retries cover files still held open by a socket that is shutting down. */
  async purge(key: string): Promise<void> {
    await rm(this.dir(key), { recursive: true, force: true, maxRetries: 5, retryDelay: 200 });
  }
}
