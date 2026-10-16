import type { Logger } from 'pino';
import type { AuthStore } from './auth-store.js';
import { classifyDisconnect, disconnectCode, disconnectReason } from './disconnect.js';
import { notOnWhatsApp, rateLimited, sessionNotOpen } from './errors.js';
import { Limiter } from './limiter.js';
import type { GatewayEvent } from './notifier.js';
import { e164Digits, phoneFromJid } from './phone.js';
import type { SocketEvents, SocketFactory, WaSocket } from './socket.js';

export type SessionState = 'connecting' | 'pairing' | 'open' | 'closed';

export interface SessionView {
  state: SessionState;
  qr: string | null;
  phone: string | null;
}

export interface SessionDeps {
  factory: SocketFactory;
  store: AuthStore;
  notify: (event: GatewayEvent) => void;
  logger: Logger;
  toQrDataUrl: (qr: string) => Promise<string>;
  pairingTimeoutMs: number;
  reconnectDelaysMs: number[];
  sendPerMinute: number;
  now?: () => number;
}

type MessageStatus = 'delivered' | 'read' | 'failed';

const STATUS_BY_ACK: Record<number, MessageStatus> = { 0: 'failed', 3: 'delivered', 4: 'read', 5: 'read' };

const TRACKED_MESSAGES = 1000;

/**
 * One linked WhatsApp number. Pairs by QR, reconnects after transient drops, and finishes itself on a fatal
 * drop, an expired QR or exhausted retries (tasks/12 lessons 1, 3, 4): it then reports `session.closed` and
 * the manager forgets it. A session that never paired is never retried and leaves no files behind.
 */
export class Session {
  private state: SessionState = 'connecting';
  private qr: string | null = null;
  private phone: string | null = null;
  private socket: WaSocket | undefined;
  private stopping = false;
  private attempts = 0;
  private pairingTimer: NodeJS.Timeout | undefined;
  private reconnectTimer: NodeJS.Timeout | undefined;
  private readonly sentIds = new Set<string>();
  private readonly limiter: Limiter;
  private readonly logger: Logger;

  constructor(
    readonly key: string,
    private readonly deps: SessionDeps,
    private readonly onFinished: (session: Session) => void,
  ) {
    this.limiter = new Limiter(deps.sendPerMinute, 60_000, deps.now);
    this.logger = deps.logger.child({ sessionKey: key });
  }

  view(): SessionView {
    return { state: this.state, qr: this.qr, phone: this.phone };
  }

  async start(): Promise<void> {
    await this.connect();
  }

  /** Sends a text to an E.164 number and resolves with the WhatsApp message id. */
  async send(to: string, text: string): Promise<string> {
    const digits = e164Digits(to);

    if (this.state !== 'open' || this.socket === undefined || digits === null) {
      throw digits === null ? notOnWhatsApp() : sessionNotOpen();
    }

    const waitMs = this.limiter.take();

    if (waitMs > 0) {
      throw rateLimited(waitMs);
    }

    const target = await this.socket.onWhatsApp(digits);

    if (target?.exists !== true) {
      throw notOnWhatsApp();
    }

    const id = await this.socket.sendText(target.jid, text);
    this.track(id);

    return id;
  }

  /**
   * Unlinks the device from the phone, then deletes the credentials (lesson 1). Resolves false when the phone
   * could not be told (the session was not open or the call failed); the files are deleted either way.
   */
  async logout(): Promise<boolean> {
    this.stopping = true;
    this.clearTimers();
    let loggedOut = false;

    if (this.socket !== undefined && this.state === 'open') {
      try {
        await this.socket.logout();
        loggedOut = true;
      } catch (error) {
        this.logger.warn({ err: error }, 'logout failed');
      }
    }

    this.endSocket();
    await this.deps.store.purge(this.key);
    this.close();

    return loggedOut;
  }

  /** Process shutdown: closes the connection and keeps the credentials for the next start. */
  stop(): void {
    this.stopping = true;
    this.clearTimers();
    this.endSocket();
    this.close();
  }

  private async connect(): Promise<void> {
    const socket = await this.deps.factory({ authDir: this.deps.store.dir(this.key), logger: this.logger });
    this.socket = socket;

    socket.on('connection.update', (update) => {
      if (socket === this.socket) {
        this.onConnectionUpdate(socket, update).catch((error: unknown) => this.logger.error({ err: error }, 'connection update failed'));
      }
    });

    socket.on('messages.update', (updates) => {
      if (socket === this.socket) {
        this.onMessagesUpdate(updates);
      }
    });
  }

  private async onConnectionUpdate(socket: WaSocket, update: SocketEvents['connection.update']): Promise<void> {
    if (this.stopping) {
      return;
    }

    if (update.qr !== undefined) {
      this.qr = await this.deps.toQrDataUrl(update.qr);
      this.state = 'pairing';
      this.pairingTimer ??= setTimeout(() => void this.finish('qr_expired', false, true), this.deps.pairingTimeoutMs);
    }

    if (update.connection === 'open') {
      this.onOpen(socket);
    }

    if (update.connection === 'close') {
      await this.onClose(disconnectCode(update.lastDisconnect?.error));
    }
  }

  private onOpen(socket: WaSocket): void {
    clearTimeout(this.pairingTimer);
    this.pairingTimer = undefined;
    this.state = 'open';
    this.qr = null;
    this.attempts = 0;
    this.phone = phoneFromJid(socket.user?.id);
    this.logger.info({ event: 'open', phone: this.phone }, 'session open');
    this.deps.notify({ event: 'session.open', sessionKey: this.key, data: { phone: this.phone } });
  }

  private async onClose(code: number | undefined): Promise<void> {
    const kind = classifyDisconnect(code);
    const reason = disconnectReason(code);
    this.logger.info({ event: 'close', statusCode: code ?? null, reason, kind }, 'session closed');

    if (kind === 'restart') {
      await this.connect();

      return;
    }

    if (kind === 'fatal') {
      await this.finish(reason, true, true);

      return;
    }

    if (!(await this.deps.store.isPaired(this.key))) {
      await this.finish('qr_expired', false, true);

      return;
    }

    const delay = this.deps.reconnectDelaysMs[this.attempts];

    if (delay === undefined) {
      await this.finish('reconnect_exhausted', false, false);

      return;
    }

    this.attempts++;
    this.state = 'connecting';
    this.reconnectTimer = setTimeout(() => {
      this.connect().catch((error: unknown) => {
        this.logger.error({ err: error }, 'reconnect failed');
        void this.onClose(undefined);
      });
    }, delay);
  }

  private onMessagesUpdate(updates: SocketEvents['messages.update']): void {
    for (const { key, update } of updates) {
      const status = typeof update.status === 'number' ? STATUS_BY_ACK[update.status] : undefined;

      if (key.id == null || key.fromMe !== true || !this.sentIds.has(key.id) || status === undefined) {
        continue;
      }

      if (status !== 'delivered') {
        this.sentIds.delete(key.id);
      }

      this.deps.notify({ event: 'message.status', sessionKey: this.key, data: { id: key.id, status } });
    }
  }

  private async finish(reason: string, fatal: boolean, purge: boolean): Promise<void> {
    if (this.stopping) {
      return;
    }

    this.stopping = true;
    this.clearTimers();
    this.endSocket();

    if (purge) {
      await this.deps.store.purge(this.key);
    }

    this.close();
    this.deps.notify({ event: 'session.closed', sessionKey: this.key, data: { reason, fatal } });
  }

  private close(): void {
    this.state = 'closed';
    this.qr = null;
    this.onFinished(this);
  }

  private endSocket(): void {
    try {
      this.socket?.end();
    } catch (error) {
      this.logger.warn({ err: error }, 'socket end failed');
    }
  }

  private clearTimers(): void {
    clearTimeout(this.pairingTimer);
    clearTimeout(this.reconnectTimer);
    this.pairingTimer = undefined;
    this.reconnectTimer = undefined;
  }

  private track(id: string): void {
    this.sentIds.add(id);

    if (this.sentIds.size > TRACKED_MESSAGES) {
      this.sentIds.delete(this.sentIds.values().next().value as string);
    }
  }
}
