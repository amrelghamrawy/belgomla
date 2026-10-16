import type { AuthStore } from './auth-store.js';
import { capacityReached, sessionNotFound, sessionNotOpen } from './errors.js';
import type { Session, SessionView } from './session.js';

export interface ManagerDeps {
  store: AuthStore;
  maxSessions: number;
  makeSession: (key: string, onFinished: (session: Session) => void) => Session;
}

/**
 * The live sessions of this process, keyed by Laravel's opaque session key (tasks/12). Laravel decides which
 * sessions should exist; this class only starts, reports, sends through and removes them, and keeps the
 * number of live sessions under the cap (lesson 7).
 */
export class SessionManager {
  private readonly sessions = new Map<string, Session>();

  constructor(private readonly deps: ManagerDeps) {}

  /** Starts a session, or reports the one already running for the key. */
  async start(key: string): Promise<SessionView> {
    const existing = this.sessions.get(key);

    if (existing !== undefined) {
      return existing.view();
    }

    if (this.sessions.size >= this.deps.maxSessions) {
      throw capacityReached();
    }

    await this.deps.store.ensure(key);
    const session = this.deps.makeSession(key, (finished) => this.forget(finished));
    this.sessions.set(key, session);

    try {
      await session.start();
    } catch (error) {
      this.forget(session);
      throw error;
    }

    return session.view();
  }

  view(key: string): SessionView {
    const session = this.sessions.get(key);

    if (session === undefined) {
      throw sessionNotFound();
    }

    return session.view();
  }

  /**
   * Logs the device out and deletes the credentials. A key with no live session only has its files deleted;
   * `loggedOut: false` then tells Laravel the phone may still list the device (lesson 1).
   */
  async remove(key: string): Promise<{ loggedOut: boolean }> {
    const session = this.sessions.get(key);

    if (session === undefined) {
      await this.deps.store.purge(key);

      return { loggedOut: false };
    }

    return { loggedOut: await session.logout() };
  }

  async send(key: string, to: string, text: string): Promise<string> {
    const session = this.sessions.get(key);

    if (session === undefined) {
      throw sessionNotOpen();
    }

    return session.send(to, text);
  }

  /** Live keys, and keys with credentials on disk (live or not), for Laravel's reconcile. */
  async list(): Promise<{ live: string[]; stored: string[] }> {
    return { live: [...this.sessions.keys()], stored: await this.deps.store.list() };
  }

  stats(): { sessions: number; open: number } {
    const open = [...this.sessions.values()].filter((session) => session.view().state === 'open').length;

    return { sessions: this.sessions.size, open };
  }

  shutdown(): void {
    for (const session of [...this.sessions.values()]) {
      session.stop();
    }
  }

  private forget(session: Session): void {
    if (this.sessions.get(session.key) === session) {
      this.sessions.delete(session.key);
    }
  }
}
