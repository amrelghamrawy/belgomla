import { pino } from 'pino';
import QRCode from 'qrcode';
import { AuthStore } from './auth-store.js';
import { loadConfig } from './config.js';
import { SessionManager } from './manager.js';
import { Notifier } from './notifier.js';
import { buildServer } from './server.js';
import { Session } from './session.js';
import { baileysSocketFactory } from './socket.js';

const config = loadConfig();
const logger = pino({ level: config.logLevel, base: { service: 'wa-gateway' } });

if (config.token === '' || config.webhookSecret === '') {
  logger.warn('WA_GATEWAY_TOKEN or WA_GATEWAY_WEBHOOK_SECRET is empty: requests or webhooks will be refused');
}

const store = new AuthStore(config.dataDir);
const notifier = new Notifier({
  url: config.webhookUrl,
  host: config.webhookHost,
  secret: config.webhookSecret,
  delaysMs: config.webhookDelaysMs,
  logger,
});

const manager = new SessionManager({
  store,
  maxSessions: config.maxSessions,
  makeSession: (key, onFinished) =>
    new Session(
      key,
      {
        factory: baileysSocketFactory,
        store,
        notify: (event) => notifier.notify(event),
        logger,
        toQrDataUrl: (qr) => QRCode.toDataURL(qr, { margin: 1, width: 320 }),
        pairingTimeoutMs: config.pairingTimeoutMs,
        reconnectDelaysMs: config.reconnectDelaysMs,
        sendPerMinute: config.sendPerMinute,
      },
      onFinished,
    ),
});

const app = buildServer({ manager, token: config.token, logger });

await app.listen({ host: config.host, port: config.port });
logger.info({ host: config.host, port: config.port }, 'wa-gateway listening');

// Laravel restarts the sessions it expects to be live (messaging:wa-reconcile) as soon as it hears this.
notifier.notify({ event: 'gateway.started', sessionKey: null, data: {} });

const shutdown = async (signal: string): Promise<void> => {
  logger.info({ signal }, 'shutting down');
  manager.shutdown();
  await app.close();
  await Promise.race([notifier.flush(), new Promise((resolve) => setTimeout(resolve, 5_000))]);
  process.exit(0);
};

process.once('SIGTERM', () => void shutdown('SIGTERM'));
process.once('SIGINT', () => void shutdown('SIGINT'));
