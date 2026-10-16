/**
 * Failures the HTTP layer turns into `{ error: code }` responses. Laravel's WaGatewayDriver reads `error`,
 * never the message.
 */
export class GatewayError extends Error {
  constructor(
    readonly status: number,
    readonly code: string,
    readonly extra: Record<string, unknown> = {},
  ) {
    super(code);
  }
}

export const capacityReached = (): GatewayError => new GatewayError(503, 'capacity');

export const sessionNotFound = (): GatewayError => new GatewayError(404, 'not_found');

export const sessionNotOpen = (): GatewayError => new GatewayError(409, 'not_open');

export const notOnWhatsApp = (): GatewayError => new GatewayError(422, 'not_on_whatsapp');

export const rateLimited = (retryAfterMs: number): GatewayError =>
  new GatewayError(429, 'rate_limited', { retry_after: Math.ceil(retryAfterMs / 1000) });
