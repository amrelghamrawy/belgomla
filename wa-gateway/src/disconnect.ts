/**
 * What a closed WhatsApp Web connection means (tasks/12 lesson 4), by Baileys' DisconnectReason code.
 *   restart    515 after a successful QR scan: reconnect at once with the new credentials.
 *   fatal      the link is gone for good (logged out from the phone, banned, replaced, corrupt): purge.
 *   transient  network or server trouble: reconnect with backoff, if the session was ever paired.
 */
export type DisconnectKind = 'restart' | 'fatal' | 'transient';

const REASONS: Record<number, string> = {
  401: 'logged_out',
  403: 'forbidden',
  408: 'timed_out',
  411: 'multidevice_mismatch',
  428: 'connection_closed',
  440: 'connection_replaced',
  500: 'bad_session',
  503: 'unavailable_service',
  515: 'restart_required',
};

const FATAL = new Set([401, 403, 411, 440, 500]);

export function classifyDisconnect(code: number | undefined): DisconnectKind {
  if (code === 515) {
    return 'restart';
  }

  return code !== undefined && FATAL.has(code) ? 'fatal' : 'transient';
}

export function disconnectReason(code: number | undefined): string {
  return (code !== undefined ? REASONS[code] : undefined) ?? 'unknown';
}

/** Baileys closes with a Boom error; its status code sits in `output.statusCode`. */
export function disconnectCode(error: unknown): number | undefined {
  const output = (error as { output?: { statusCode?: unknown } } | undefined)?.output;

  return typeof output?.statusCode === 'number' ? output.statusCode : undefined;
}
