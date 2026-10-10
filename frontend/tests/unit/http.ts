import { vi } from "vitest";

/** A JSON response like Laravel's. `null` body → no content. */
export function json(status: number, body: unknown = null, headers: Record<string, string> = {}): Response {
  return new Response(body === null ? null : JSON.stringify(body), {
    status,
    headers: { "Content-Type": "application/json", ...headers },
  });
}

export function envelopeError(status: number, code: string, message: string, details?: Record<string, unknown>) {
  return json(status, { error: { code, message, ...(details ? { details } : {}) } });
}

/**
 * Replaces global fetch with a queue of responses, answered in order. A function entry runs instead
 * (e.g. to set a cookie the way Sanctum's csrf-cookie does). Returns the mock to inspect the calls.
 */
export function stubFetch(...responses: (Response | (() => Response))[]) {
  const queue = [...responses];
  const fetchMock = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
    void input;
    void init;
    const next = queue.shift();

    if (next === undefined) {
      throw new Error("Unexpected fetch: no response queued");
    }

    return typeof next === "function" ? next() : next;
  });

  vi.stubGlobal("fetch", fetchMock);

  return fetchMock;
}

/** What Sanctum's `GET /sanctum/csrf-cookie` does: set the XSRF-TOKEN cookie, answer 204. */
export function csrfCookie(token = "token%3D1") {
  return () => {
    document.cookie = `XSRF-TOKEN=${token}; path=/`;

    return json(204);
  };
}

export function requestOf(fetchMock: ReturnType<typeof stubFetch>, call: number) {
  const [url, init] = fetchMock.mock.calls[call] ?? [];

  return { url: String(url), init: init ?? {}, headers: (init?.headers ?? {}) as Record<string, string> };
}
