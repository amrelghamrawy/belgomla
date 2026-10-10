import { dehydrate, QueryClient, type DehydratedState } from "@tanstack/react-query";
import { headers } from "next/headers";

import type { Page } from "@/lib/api/client";
import { queryKeys } from "@/lib/api/keys";
import type { Category, StoreProduct } from "@/lib/api/types";
import { apiParams, type Listing } from "@/lib/store/listing";

import { backendTarget, proxyToBackend, type BackendTarget } from "./backend-proxy";

// Server-side reads of public storefront data, so the first screen of a listing arrives in the HTML instead of
// after the JavaScript (tasks/9: Lighthouse mobile ≥ 90). They go to Laravel through the same Host-preserving
// proxy as the browser's calls, as the shopper (cookies forwarded: a logged-in customer sees prices, D1). Any
// failure returns null and the page simply loads the data in the browser, as before.

const PAGE_SIZE = 24;

/** GET a storefront path as the current request's shopper; the parsed body, or null on any failure. */
export async function readStoreJson(path: string, target: BackendTarget = backendTarget()): Promise<unknown> {
  const incoming = await headers();
  const host = incoming.get("host");

  if (host === null) {
    return null;
  }

  const origin = `${incoming.get("x-forwarded-proto") ?? "http"}://${host}`;
  const forwarded: Record<string, string> = {
    host,
    accept: "application/json",
    "x-requested-with": "XMLHttpRequest",
    // Sanctum treats the call as the SPA (session cookie honoured) only with a Referer from a stateful domain.
    referer: `${origin}/`,
  };
  const cookie = incoming.get("cookie");

  if (cookie !== null) {
    forwarded.cookie = cookie;
  }

  try {
    const response = await proxyToBackend(new Request(`${origin}${path}`, { headers: forwarded }), target);

    return response.ok ? ((await response.json()) as unknown) : null;
  } catch {
    return null;
  }
}

function isPage(body: unknown): body is Page<StoreProduct> {
  return typeof body === "object" && body !== null && "data" in body && "meta" in body && Array.isArray(body.data);
}

function isList<T>(body: unknown): body is { data: T[] } {
  return typeof body === "object" && body !== null && "data" in body && Array.isArray(body.data);
}

function query(params: Record<string, unknown>): string {
  const search = new URLSearchParams();

  for (const [key, value] of Object.entries(params)) {
    if (typeof value === "string" || typeof value === "number") {
      search.append(key, String(value));
    } else if (typeof value === "object" && value !== null) {
      for (const [nested, values] of Object.entries(value)) {
        for (const item of values as string[]) {
          search.append(`${key}[${nested}]`, item);
        }
      }
    }
  }

  return search.toString();
}

/**
 * The cache a listing page starts with: the category tree and the first page of products, under the exact keys
 * the client hooks use (catalog.ts), so TanStack Query renders them at once and only fetches what is missing.
 */
export async function prefetchListing(listing: Listing): Promise<DehydratedState> {
  const client = new QueryClient();
  const params = apiParams(listing);

  const [products, categories] = await Promise.all([
    readStoreJson(`/api/store/products?${query({ ...params, page: 1, per_page: PAGE_SIZE })}`),
    readStoreJson("/api/store/categories"),
  ]);

  if (isPage(products)) {
    client.setQueryData(queryKeys.products(params), {
      pages: [{ data: products.data, meta: products.meta }],
      pageParams: [1],
    });
  }

  if (isList<Category>(categories)) {
    client.setQueryData(queryKeys.categories, categories.data);
  }

  return dehydrate(client);
}

/** Next's searchParams object (`{ "f[grade]": "7" }`) as URLSearchParams, for parseListing. */
export function toSearchParams(params: Record<string, string | string[] | undefined>): URLSearchParams {
  const search = new URLSearchParams();

  for (const [key, value] of Object.entries(params)) {
    for (const item of Array.isArray(value) ? value : value === undefined ? [] : [value]) {
      search.append(key, item);
    }
  }

  return search;
}
