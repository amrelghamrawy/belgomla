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

type ApiRequest = { url: URL; method: string; headers: Record<string, string>; body: unknown };
type ApiAnswer = Response | ((request: ApiRequest) => Response);

/**
 * Replaces fetch with a router: `{ "GET /api/store/cart": json(200, …) }`. Matching is on method + path (the query
 * is ignored; a function answer can read it). An array answers in turn and then keeps repeating its last entry, so
 * refetches work. `/sanctum/csrf-cookie` is answered with a cookie unless listed. Unrouted calls throw.
 */
export function stubApi(routes: Record<string, ApiAnswer | ApiAnswer[]>) {
  const queues = new Map(
    Object.entries(routes).map(([key, answer]) => [key, Array.isArray(answer) ? [...answer] : [answer]]),
  );
  const fetchMock = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
    const url = new URL(String(input), "http://alnoor.gomal.test");
    const method = init?.method ?? "GET";
    const key = `${method} ${url.pathname}`;
    const queue = queues.get(key);

    if (queue === undefined) {
      if (key === "GET /sanctum/csrf-cookie") {
        return csrfCookie()();
      }

      throw new Error(`Unexpected fetch: ${key}`);
    }

    const answer = queue.length > 1 ? queue.shift() : queue[0];
    const request = {
      url,
      method,
      headers: (init?.headers ?? {}) as Record<string, string>,
      body: typeof init?.body === "string" ? JSON.parse(init.body) : undefined,
    };

    if (answer === undefined) {
      throw new Error(`No answer for ${key}`);
    }

    return typeof answer === "function" ? answer(request) : answer.clone();
  });

  vi.stubGlobal("fetch", fetchMock);

  return fetchMock;
}

/** The requests a stubApi/stubFetch mock received for `"METHOD /path"`, with parsed JSON bodies. */
export function callsTo(fetchMock: ReturnType<typeof stubApi>, key: string) {
  return fetchMock.mock.calls
    .map(([input, init]) => ({
      url: new URL(String(input), "http://alnoor.gomal.test"),
      method: init?.method ?? "GET",
      headers: (init?.headers ?? {}) as Record<string, string>,
      body: typeof init?.body === "string" ? (JSON.parse(init.body) as unknown) : undefined,
    }))
    .filter((call) => `${call.method} ${call.url.pathname}` === key);
}
