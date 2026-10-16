export interface Config {
  host: string;
  port: number;
  token: string;
  webhookUrl: string;
  webhookHost: string;
  webhookSecret: string;
  dataDir: string;
  maxSessions: number;
  sendPerMinute: number;
  pairingTimeoutMs: number;
  reconnectDelaysMs: number[];
  webhookDelaysMs: number[];
  logLevel: string;
}

/** Reads `WA_GATEWAY_*` (deploy/env/wa-gateway.env.example). Listens on 127.0.0.1 unless told otherwise. */
export function loadConfig(env: NodeJS.ProcessEnv = process.env): Config {
  return {
    host: env.WA_GATEWAY_HOST ?? '127.0.0.1',
    port: integer(env.WA_GATEWAY_PORT, 3100),
    token: env.WA_GATEWAY_TOKEN ?? '',
    webhookUrl: env.WA_GATEWAY_WEBHOOK_URL ?? '',
    webhookHost: env.WA_GATEWAY_WEBHOOK_HOST ?? '',
    webhookSecret: env.WA_GATEWAY_WEBHOOK_SECRET ?? '',
    dataDir: env.WA_GATEWAY_DATA_DIR ?? './data/sessions',
    maxSessions: integer(env.WA_GATEWAY_MAX_SESSIONS, 50),
    sendPerMinute: integer(env.WA_GATEWAY_SEND_PER_MINUTE, 10),
    pairingTimeoutMs: 120_000,
    reconnectDelaysMs: [5_000, 15_000, 30_000, 60_000, 120_000],
    webhookDelaysMs: [1_000, 3_000, 10_000, 30_000],
    logLevel: env.WA_GATEWAY_LOG_LEVEL ?? 'info',
  };
}

function integer(value: string | undefined, fallback: number): number {
  const parsed = Number.parseInt(value ?? '', 10);

  return Number.isFinite(parsed) && parsed > 0 ? parsed : fallback;
}
