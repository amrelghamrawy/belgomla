import { request as httpRequest, type IncomingHttpHeaders } from "node:http";

// Dev/self-hosted proxy for `/api/*` and `/sanctum/*` (Tech §5, rules/03 §4). Laravel resolves the tenant
// from the Host header only, so the request reaches it with the browser's Host unchanged
// (alnoor.gomal.test:3000 → tenant alnoor). Next's built-in external rewrites cannot do this: they replace
// Host with the destination host. Production Nginx sends these paths to PHP-FPM before they reach Next.

const HOP_BY_HOP = new Set([
  "connection",
  "keep-alive",
  "proxy-connection",
  "transfer-encoding",
  "upgrade",
  "te",
  "trailer",
  "content-length",
]);

export type BackendTarget = { host: string; port: number };

export function backendTarget(env: Record<string, string | undefined> = process.env): BackendTarget {
  return { host: env.BACKEND_HOST ?? "127.0.0.1", port: Number(env.BACKEND_PORT ?? 8000) };
}

function forwardedHeaders(request: Request): Record<string, string> {
  const url = new URL(request.url);
  const headers: Record<string, string> = {};

  request.headers.forEach((value, key) => {
    if (!HOP_BY_HOP.has(key)) {
      headers[key] = value;
    }
  });

  headers.host = request.headers.get("host") ?? url.host;
  headers["x-forwarded-proto"] = url.protocol.replace(":", "");

  return headers;
}

function responseHeaders(incoming: IncomingHttpHeaders): Headers {
  const headers = new Headers();

  for (const [key, value] of Object.entries(incoming)) {
    if (value === undefined || HOP_BY_HOP.has(key)) {
      continue;
    }

    // Laravel sends several Set-Cookie headers (session + XSRF-TOKEN); keep each one.
    for (const item of Array.isArray(value) ? value : [value]) {
      headers.append(key, item);
    }
  }

  return headers;
}

function unreachable(): Response {
  return Response.json(
    { error: { code: "server_error", message: "تعذر الوصول إلى الخادم، حاول مرة أخرى" } },
    { status: 502 },
  );
}

/** Forwards the request to Laravel with the original Host, cookies, Origin and Referer, and returns its answer. */
export async function proxyToBackend(request: Request, target: BackendTarget = backendTarget()): Promise<Response> {
  const url = new URL(request.url);
  const body = request.method === "GET" || request.method === "HEAD" ? null : Buffer.from(await request.arrayBuffer());
  const headers = forwardedHeaders(request);

  if (body !== null) {
    headers["content-length"] = String(body.length);
  }

  return new Promise((resolve) => {
    const outgoing = httpRequest(
      { host: target.host, port: target.port, method: request.method, path: url.pathname + url.search, headers },
      (incoming) => {
        const chunks: Buffer[] = [];

        incoming.on("data", (chunk: Buffer) => chunks.push(chunk));
        incoming.on("end", () => {
          const status = incoming.statusCode ?? 502;
          const empty = status === 204 || status === 304 || request.method === "HEAD";

          resolve(
            new Response(empty ? null : Buffer.concat(chunks), { status, headers: responseHeaders(incoming.headers) }),
          );
        });
        incoming.on("error", () => resolve(unreachable()));
      },
    );

    outgoing.on("error", () => resolve(unreachable()));
    outgoing.end(body ?? undefined);
  });
}
