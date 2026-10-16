// @vitest-environment node
import { createServer, type IncomingHttpHeaders, type Server } from "node:http";
import type { AddressInfo } from "node:net";

import { hydrate, QueryClient } from "@tanstack/react-query";
import { afterAll, beforeAll, beforeEach, describe, expect, it, vi } from "vitest";

import { queryKeys } from "@/lib/api/keys";
import { apiParams } from "@/lib/store/listing";

const incoming = { value: new Headers() };

vi.mock("next/headers", () => ({ headers: async () => incoming.value }));

let server: Server;
let port: number;
let seen: { url?: string; headers: IncomingHttpHeaders }[];
let answers: Record<string, { status: number; body: unknown }>;

beforeAll(async () => {
  server = createServer((req, res) => {
    seen.push({ url: req.url, headers: req.headers });
    const answer = answers[(req.url ?? "").split("?")[0] ?? ""] ?? {
      status: 404,
      body: { error: { code: "not_found" } },
    };
    res.writeHead(answer.status, { "Content-Type": "application/json" });
    res.end(JSON.stringify(answer.body));
  });
  await new Promise<void>((resolve) => server.listen(0, "127.0.0.1", resolve));
  port = (server.address() as AddressInfo).port;
  process.env.BACKEND_HOST = "127.0.0.1";
  process.env.BACKEND_PORT = String(port);
});

afterAll(() => {
  server.close();
  delete process.env.BACKEND_HOST;
  delete process.env.BACKEND_PORT;
});

beforeEach(() => {
  seen = [];
  answers = {};
  incoming.value = new Headers({ host: "alnoor.gomal.test:3000", cookie: "belgomla_session=abc" });
});

const meta = { current_page: 1, from: 1, last_page: 2, links: [], path: "/", per_page: 24, to: 24, total: 31 };

describe("readStoreJson", () => {
  it("asks Laravel as the shopper: same Host, their cookies, and a Referer so Sanctum keeps the session", async () => {
    const { readStoreJson } = await import("@/lib/server/store-data");
    answers["/api/store/categories"] = { status: 200, body: { data: [] } };

    await expect(readStoreJson("/api/store/categories")).resolves.toEqual({ data: [] });

    expect(seen[0]?.headers.host).toBe("alnoor.gomal.test:3000");
    expect(seen[0]?.headers.cookie).toBe("belgomla_session=abc");
    expect(seen[0]?.headers.referer).toBe("http://alnoor.gomal.test:3000/");
    expect(seen[0]?.headers.accept).toBe("application/json");
  });

  it("is null for an error answer, so the browser loads the data itself", async () => {
    const { readStoreJson } = await import("@/lib/server/store-data");
    answers["/api/store/categories"] = { status: 503, body: { error: { code: "store_unavailable" } } };

    await expect(readStoreJson("/api/store/categories")).resolves.toBeNull();
  });

  it("is null without a Host", async () => {
    const { readStoreJson } = await import("@/lib/server/store-data");
    incoming.value = new Headers();

    await expect(readStoreJson("/api/store/categories")).resolves.toBeNull();
    expect(seen).toHaveLength(0);
  });
});

describe("prefetchListing", () => {
  it("fills the first page and the category tree under the keys the client hooks read", async () => {
    const { prefetchListing } = await import("@/lib/server/store-data");
    const product = { id: 1, name: "علوم" };
    answers["/api/store/products"] = { status: 200, body: { data: [product], links: {}, meta } };
    answers["/api/store/categories"] = { status: 200, body: { data: [{ id: 1, slug: "prep", children: [] }] } };
    const listing = { category: "prep", f: { grade: "7" } };

    const client = new QueryClient();
    const prefetched = await prefetchListing(listing);
    hydrate(client, prefetched.state);

    expect(client.getQueryData(queryKeys.products(apiParams(listing)))).toEqual({
      pages: [{ data: [product], meta }],
      pageParams: [1],
    });
    expect(client.getQueryData(queryKeys.categories)).toEqual([{ id: 1, slug: "prep", children: [] }]);
    expect(prefetched.categories).toEqual([{ id: 1, slug: "prep", children: [] }]);
    expect(seen.find((call) => call.url?.startsWith("/api/store/products"))?.url).toBe(
      "/api/store/products?category=prep&f%5Bgrade%5D=7&page=1&per_page=24",
    );
  });

  it("leaves out what the backend could not give", async () => {
    const { prefetchListing } = await import("@/lib/server/store-data");

    const client = new QueryClient();
    const prefetched = await prefetchListing({ f: {} });
    hydrate(client, prefetched.state);

    expect(client.getQueryCache().getAll()).toHaveLength(0);
    expect(prefetched.categories).toBeNull();
  });
});

describe("toSearchParams", () => {
  it("turns Next's searchParams object back into URLSearchParams, repeated keys included", async () => {
    const { toSearchParams } = await import("@/lib/server/store-data");

    expect(toSearchParams({ "f[grade]": "7", q: ["a", "b"], sort: undefined }).toString()).toBe(
      "f%5Bgrade%5D=7&q=a&q=b",
    );
  });
});
