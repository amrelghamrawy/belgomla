import { createHmac } from 'node:crypto';
import { request as httpRequest } from 'node:http';
import { request as httpsRequest } from 'node:https';
import type { Logger } from 'pino';

export type GatewayEventName = 'gateway.started' | 'session.open' | 'session.closed' | 'message.status';

export interface GatewayEvent {
  event: GatewayEventName;
  sessionKey: string | null;
  data: Record<string, unknown>;
}

/** Sends one POST and resolves with the HTTP status. */
export type Poster = (url: string, headers: Record<string, string>, body: string) => Promise<number>;

export interface NotifierOptions {
  url: string;
  secret: string;
  /** Host header override, for reaching `hooks.{base_domain}` through 127.0.0.1. */
  host?: string;
  delaysMs: number[];
  logger: Logger;
  post?: Poster;
  wait?: (ms: number) => Promise<void>;
  now?: () => Date;
}

/**
 * Tells Laravel what happened to a session or a message (tasks/12 lesson 5). The body is signed with
 * HMAC-SHA256 over `{timestamp}.{body}`; Laravel refuses a missing, wrong or stale signature. Delivery is
 * retried on network errors, 429 and 5xx; any other 4xx is final.
 */
export class Notifier {
  private readonly pending = new Set<Promise<void>>();

  constructor(private readonly options: NotifierOptions) {}

  static sign(secret: string, timestamp: string, body: string): string {
    return createHmac('sha256', secret).update(`${timestamp}.${body}`).digest('hex');
  }

  notify(event: GatewayEvent): void {
    if (this.options.url === '') {
      this.options.logger.warn({ event: event.event, sessionKey: event.sessionKey }, 'webhook url not set, event dropped');

      return;
    }

    const delivery = this.deliver(event).finally(() => this.pending.delete(delivery));
    this.pending.add(delivery);
  }

  /** Resolves once every event handed over so far was delivered or given up. */
  async flush(): Promise<void> {
    await Promise.all([...this.pending]);
  }

  private async deliver(event: GatewayEvent): Promise<void> {
    const now = this.options.now ?? (() => new Date());
    const body = JSON.stringify({ event: event.event, session_key: event.sessionKey, data: event.data, sent_at: now().toISOString() });
    const post = this.options.post ?? defaultPoster;
    const wait = this.options.wait ?? ((ms: number) => new Promise<void>((resolve) => setTimeout(resolve, ms)));

    for (let attempt = 0; ; attempt++) {
      const timestamp = String(Math.floor(now().getTime() / 1000));
      const headers: Record<string, string> = {
        'content-type': 'application/json',
        accept: 'application/json',
        'x-gateway-timestamp': timestamp,
        'x-gateway-signature': Notifier.sign(this.options.secret, timestamp, body),
      };

      if (this.options.host !== undefined && this.options.host !== '') {
        headers.host = this.options.host;
      }

      const status = await post(this.options.url, headers, body).catch(() => 0);

      if (status >= 200 && status < 300) {
        return;
      }

      const retryable = status === 0 || status === 429 || status >= 500;
      const delay = this.options.delaysMs[attempt];

      if (!retryable || delay === undefined) {
        this.options.logger.error({ event: event.event, sessionKey: event.sessionKey, status, attempts: attempt + 1 }, 'webhook delivery failed');

        return;
      }

      await wait(delay);
    }
  }
}

const defaultPoster: Poster = (url, headers, body) =>
  new Promise((resolve, reject) => {
    const target = new URL(url);
    const send = target.protocol === 'https:' ? httpsRequest : httpRequest;
    const req = send(target, { method: 'POST', headers: { ...headers, 'content-length': String(Buffer.byteLength(body)) }, timeout: 10_000 }, (res) => {
      res.resume();
      resolve(res.statusCode ?? 0);
    });

    req.on('timeout', () => req.destroy(new Error('timeout')));
    req.on('error', reject);
    req.end(body);
  });
