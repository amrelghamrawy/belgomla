// @vitest-environment node
import { createServer, type IncomingHttpHeaders, type Server } from "node:http";
import type { AddressInfo } from "node:net";

import { afterAll, beforeAll, describe, expect, it } from "vitest";

import { backendTarget, proxyToBackend } from "@/lib/server/backend-proxy";

type Seen = { method?: string; url?: string; headers: IncomingHttpHeaders; body: string };

let server: Server;
let port: number;
let seen: Seen;

beforeAll(async () => {
  server = createServer((req, res) => {
    let body = "";
    req.on("data", (chunk: Buffer) => (body += chunk.toString()));
    req.on("end", () => {
      seen = { method: req.method, url: req.url, headers: req.headers, body };

      if (req.url?.startsWith("/sanctum")) {
        res.writeHead(204, { "Set-Cookie": ["XSRF-TOKEN=abc; path=/", "belgomla_session=xyz; path=/; httponly"] });
        res.end();

        return;
      }

      res.writeHead(200, { "Content-Type": "application/json" });
      res.end(JSON.stringify({ data: { ok: true } }));
    });
  });
  await new Promise<void>((resolve) => server.listen(0, "127.0.0.1", resolve));
  port = (server.address() as AddressInfo).port;
});

afterAll(() => {
  server.close();
});

function tenantRequest(path: string, init: RequestInit = {}) {
  const headers = new Headers(init.headers);
  headers.set("host", "alnoor.gomal.test:3000");

  return new Request(`http://alnoor.gomal.test:3000${path}`, { ...init, headers });
}

describe("proxyToBackend", () => {
  it("keeps the tenant Host so Laravel resolves the right store", async () => {
    const response = await proxyToBackend(tenantRequest("/api/store/tenant?x=1"), { host: "127.0.0.1", port });

    expect(seen.headers.host).toBe("alnoor.gomal.test:3000");
    expect(seen.url).toBe("/api/store/tenant?x=1");
    expect(response.status).toBe(200);
    await expect(response.json()).resolves.toEqual({ data: { ok: true } });
  });

  it("forwards method, body, cookies, Origin and the XSRF header", async () => {
    await proxyToBackend(
      tenantRequest("/api/dashboard/auth/login", {
        method: "POST",
        body: '{"email":"a@b.c"}',
        headers: {
          "content-type": "application/json",
          cookie: "belgomla_session=s1",
          origin: "http://alnoor.gomal.test:3000",
          "x-xsrf-token": "t1",
        },
      }),
      { host: "127.0.0.1", port },
    );

    expect(seen.method).toBe("POST");
    expect(seen.body).toBe('{"email":"a@b.c"}');
    expect(seen.headers).toMatchObject({
      cookie: "belgomla_session=s1",
      origin: "http://alnoor.gomal.test:3000",
      "x-xsrf-token": "t1",
      "content-length": "17",
    });
  });

  it("passes every Set-Cookie back to the browser", async () => {
    const response = await proxyToBackend(tenantRequest("/sanctum/csrf-cookie"), { host: "127.0.0.1", port });

    expect(response.status).toBe(204);
    expect(response.headers.getSetCookie()).toEqual([
      "XSRF-TOKEN=abc; path=/",
      "belgomla_session=xyz; path=/; httponly",
    ]);
  });

  it("answers 502 with an error envelope when Laravel is not running", async () => {
    const response = await proxyToBackend(tenantRequest("/api/store/tenant"), { host: "127.0.0.1", port: 1 });

    expect(response.status).toBe(502);
    await expect(response.json()).resolves.toMatchObject({ error: { code: "server_error" } });
  });
});

describe("the /api and /sanctum route handlers", () => {
  it("proxy with the configured backend even though Next passes a route context", async () => {
    const { GET } = await import("@/app/api/[...path]/route");
    const previous = process.env.BACKEND_PORT;
    process.env.BACKEND_PORT = String(port);

    try {
      const response = await (GET as (request: Request, context: unknown) => Promise<Response>)(
        tenantRequest("/api/store/tenant"),
        { params: Promise.resolve({ path: ["store", "tenant"] }) },
      );

      expect(response.status).toBe(200);
      expect(seen.headers.host).toBe("alnoor.gomal.test:3000");
    } finally {
      process.env.BACKEND_PORT = previous;
    }
  });

  it("are the same handler for every method on both paths", async () => {
    const api = await import("@/app/api/[...path]/route");
    const sanctum = await import("@/app/sanctum/[...path]/route");

    expect(new Set([api.GET, api.POST, api.PUT, api.PATCH, api.DELETE, sanctum.GET, sanctum.POST]).size).toBe(2);
  });
});

describe("backendTarget", () => {
  it("defaults to the local artisan server", () => {
    expect(backendTarget({})).toEqual({ host: "127.0.0.1", port: 8000 });
  });

  it("reads BACKEND_HOST and BACKEND_PORT", () => {
    expect(backendTarget({ BACKEND_HOST: "10.0.0.5", BACKEND_PORT: "9000" })).toEqual({ host: "10.0.0.5", port: 9000 });
  });
});
