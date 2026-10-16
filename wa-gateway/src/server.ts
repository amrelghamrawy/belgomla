import { timingSafeEqual } from 'node:crypto';
import Fastify, { type FastifyError, type FastifyInstance } from 'fastify';
import type { Logger } from 'pino';
import { SESSION_KEY } from './auth-store.js';
import { GatewayError, sessionNotFound } from './errors.js';
import type { SessionManager } from './manager.js';
import type { SessionView } from './session.js';

export interface ServerDeps {
  manager: SessionManager;
  token: string;
  logger: Logger;
}

interface KeyParams {
  key: string;
}

interface SendBody {
  to: string;
  text: string;
}

const keyParams = {
  type: 'object',
  required: ['key'],
  properties: { key: { type: 'string' } },
} as const;

const sendBody = {
  type: 'object',
  required: ['to', 'text'],
  additionalProperties: false,
  properties: {
    to: { type: 'string', pattern: '^\\+[1-9][0-9]{7,14}$' },
    text: { type: 'string', minLength: 1, maxLength: 4096 },
  },
} as const;

/**
 * The gateway's HTTP API, called only by Laravel over 127.0.0.1 (tasks/12). Every route except `/health`
 * needs `Authorization: Bearer <token>`; an empty configured token refuses everything (lesson 5).
 */
export function buildServer({ manager, token, logger }: ServerDeps): FastifyInstance {
  // Unknown body fields are refused, not silently stripped (Fastify's default).
  const app = Fastify({ logger: false, bodyLimit: 64 * 1024, ajv: { customOptions: { removeAdditional: false } } });

  app.addHook('onRequest', async (request, reply) => {
    if (request.url === '/health') {
      return;
    }

    if (!authorized(request.headers.authorization, token)) {
      await reply.code(401).send({ error: 'unauthenticated' });
    }
  });

  app.addHook('preValidation', async (request) => {
    const key = (request.params as Partial<KeyParams> | undefined)?.key;

    if (key !== undefined && !SESSION_KEY.test(key)) {
      throw sessionNotFound();
    }
  });

  app.get('/health', async () => ({ ok: true, ...manager.stats() }));

  app.get('/sessions', async () => manager.list());

  app.post<{ Params: KeyParams }>('/sessions/:key', { schema: { params: keyParams } }, async (request) =>
    present(await manager.start(request.params.key)),
  );

  app.get<{ Params: KeyParams }>('/sessions/:key', { schema: { params: keyParams } }, async (request) =>
    present(manager.view(request.params.key)),
  );

  app.delete<{ Params: KeyParams }>('/sessions/:key', { schema: { params: keyParams } }, async (request) => {
    const { loggedOut } = await manager.remove(request.params.key);

    return { logged_out: loggedOut };
  });

  app.post<{ Params: KeyParams; Body: SendBody }>(
    '/sessions/:key/messages',
    { schema: { params: keyParams, body: sendBody } },
    async (request) => ({ id: await manager.send(request.params.key, request.body.to, request.body.text) }),
  );

  app.setNotFoundHandler(async (_request, reply) => reply.code(404).send({ error: 'not_found' }));

  app.setErrorHandler(async (error: FastifyError | GatewayError, request, reply) => {
    if (error instanceof GatewayError) {
      return reply.code(error.status).send({ error: error.code, ...error.extra });
    }

    if (error.validation !== undefined) {
      return reply.code(400).send({ error: 'validation_failed' });
    }

    logger.error({ err: error, method: request.method, url: request.url }, 'request failed');

    return reply.code(500).send({ error: 'server_error' });
  });

  return app;
}

function present(view: SessionView): Record<string, unknown> {
  return { state: view.state, qr: view.qr, phone: view.phone };
}

function authorized(header: string | undefined, token: string): boolean {
  if (token === '' || header === undefined) {
    return false;
  }

  const expected = Buffer.from(`Bearer ${token}`);
  const given = Buffer.from(header);

  return given.length === expected.length && timingSafeEqual(given, expected);
}
