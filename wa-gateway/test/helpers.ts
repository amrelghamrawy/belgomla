import { mkdir, mkdtemp, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { pino } from 'pino';
import { AuthStore } from '../src/auth-store.js';
import { SessionManager } from '../src/manager.js';
import type { GatewayEvent } from '../src/notifier.js';
import { Session, type SessionDeps } from '../src/session.js';
import type { SocketEvents, WaSocket } from '../src/socket.js';

export const KEY = '0b4d2f6e-1c3a-4e5b-9d7f-2a8c6e4b1d3f';
export const OTHER_KEY = '7e1a9c3b-5d2f-4a6e-8b0c-4f2d6a8e0c1b';
export const PHONE_JID = '201001234567:7@s.whatsapp.net';

type Handlers = { [E in keyof SocketEvents]: ((data: SocketEvents[E]) => void)[] };

export class FakeSocket implements WaSocket {
  user: { id: string } | undefined;
  readonly handlers: Handlers = { 'connection.update': [], 'messages.update': [] };
  readonly onWhatsAppNumbers = new Set<string>();
  readonly sent: { jid: string; text: string }[] = [];
  logoutError: Error | undefined;
  loggedOut = false;
  ended = false;

  on<E extends keyof SocketEvents>(event: E, handler: (data: SocketEvents[E]) => void): void {
    this.handlers[event].push(handler);
  }

  emit<E extends keyof SocketEvents>(event: E, data: SocketEvents[E]): void {
    for (const handler of this.handlers[event]) {
      handler(data);
    }
  }

  async onWhatsApp(digits: string): Promise<{ exists: boolean; jid: string }> {
    return { exists: this.onWhatsAppNumbers.has(digits), jid: `${digits}@s.whatsapp.net` };
  }

  async sendText(jid: string, text: string): Promise<string> {
    this.sent.push({ jid, text });

    return `MSG${this.sent.length}`;
  }

  async logout(): Promise<void> {
    if (this.logoutError !== undefined) {
      throw this.logoutError;
    }

    this.loggedOut = true;
  }

  end(): void {
    this.ended = true;
  }

  qr(code = 'qr-ref'): void {
    this.emit('connection.update', { qr: code });
  }

  open(jid = PHONE_JID): void {
    this.user = { id: jid };
    this.emit('connection.update', { connection: 'open' });
  }

  close(statusCode?: number): void {
    const error = statusCode === undefined ? undefined : Object.assign(new Error('closed'), { output: { statusCode } });
    this.emit('connection.update', { connection: 'close', lastDisconnect: { error, date: new Date() } });
  }
}

export interface Harness {
  store: AuthStore;
  root: string;
  sockets: FakeSocket[];
  events: GatewayEvent[];
  deps: SessionDeps;
  manager: SessionManager;
  socket: () => FakeSocket;
}

export async function harness(overrides: Partial<SessionDeps> & { maxSessions?: number } = {}): Promise<Harness> {
  const root = await mkdtemp(join(tmpdir(), 'wa-gateway-'));
  const store = new AuthStore(root);
  const sockets: FakeSocket[] = [];
  const events: GatewayEvent[] = [];

  const deps: SessionDeps = {
    factory: async () => {
      const socket = new FakeSocket();
      sockets.push(socket);

      return socket;
    },
    store,
    notify: (event) => events.push(event),
    logger: pino({ level: 'silent' }),
    toQrDataUrl: async (qr) => `data:image/png;base64,${qr}`,
    pairingTimeoutMs: 60_000,
    reconnectDelaysMs: [10, 20],
    sendPerMinute: 10,
    ...overrides,
  };

  const manager = new SessionManager({
    store,
    maxSessions: overrides.maxSessions ?? 5,
    makeSession: (key, onFinished) => new Session(key, deps, onFinished),
  });

  const socket = (): FakeSocket => {
    const last = sockets.at(-1);

    if (last === undefined) {
      throw new Error('no socket was created');
    }

    return last;
  };

  return { store, root, sockets, events, deps, manager, socket };
}

/** Writes the credentials a completed QR scan leaves behind. */
export async function markPaired(store: AuthStore, key: string): Promise<void> {
  await mkdir(store.dir(key), { recursive: true });
  await writeFile(join(store.dir(key), 'creds.json'), JSON.stringify({ me: { id: PHONE_JID } }));
}

/** Lets pending promise callbacks and I/O run. */
export async function settle(): Promise<void> {
  for (let i = 0; i < 5; i++) {
    await new Promise((resolve) => setImmediate(resolve));
  }
}
