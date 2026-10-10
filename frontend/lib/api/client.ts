import { ar } from "@/lib/copy/ar";

import { ApiError, StoreUnavailableError, type ApiErrorCode, type ErrorDetails } from "./errors";

/** Which UI a call belongs to; decides where a 401 sends the user. */
export type Surface = "store" | "dashboard";

export type ApiOptions = {
  method?: "GET" | "POST" | "PUT" | "PATCH" | "DELETE";
  body?: unknown;
  /** Defaults to true. Session probes (`me`) and the auth endpoints pass false and handle 401 themselves. */
  redirectOn401?: boolean;
  signal?: AbortSignal;
};

type UnauthenticatedHandler = (surface: Surface) => void;

const CSRF_COOKIE = "XSRF-TOKEN";

export const loginPaths: Record<Surface, string> = {
  store: "/account/login",
  dashboard: "/dashboard/login",
};

function redirectToLogin(surface: Surface): void {
  const next = window.location.pathname + window.location.search;

  // A full navigation on purpose: the expired session's cached data must not survive (rules/03 §1).
  // eslint-disable-next-line @next/next/no-location-assign-relative-destination
  window.location.assign(`${loginPaths[surface]}?next=${encodeURIComponent(next)}`);
}

let onUnauthenticated: UnauthenticatedHandler = redirectToLogin;

/** Replaces the 401 behaviour (tests, or a screen that wants to handle it). Returns the previous handler. */
export function setUnauthenticatedHandler(handler: UnauthenticatedHandler): UnauthenticatedHandler {
  const previous = onUnauthenticated;
  onUnauthenticated = handler;

  return previous;
}

export function surfaceOf(path: string): Surface {
  return path.startsWith("/api/dashboard") ? "dashboard" : "store";
}

function readCsrfToken(): string | null {
  const cookie = document.cookie.split("; ").find((part) => part.startsWith(`${CSRF_COOKIE}=`));

  return cookie ? decodeURIComponent(cookie.slice(CSRF_COOKIE.length + 1)) : null;
}

async function send(path: string, init: RequestInit): Promise<Response> {
  try {
    return await fetch(path, { credentials: "include", ...init });
  } catch (error) {
    if (error instanceof DOMException && error.name === "AbortError") {
      throw error;
    }

    throw new ApiError(0, "network_error", ar.errors.network_error);
  }
}

/** Asks Sanctum for a fresh XSRF-TOKEN cookie (`GET /sanctum/csrf-cookie`). */
export async function refreshCsrfCookie(): Promise<void> {
  const response = await send("/sanctum/csrf-cookie", { headers: { Accept: "application/json" } });

  if (!response.ok) {
    throw await toApiError(response);
  }
}

function request(path: string, options: ApiOptions): Promise<Response> {
  const headers: Record<string, string> = {
    Accept: "application/json",
    "X-Requested-With": "XMLHttpRequest",
  };
  const token = readCsrfToken();

  if (token !== null) {
    headers["X-XSRF-TOKEN"] = token;
  }

  if (options.body !== undefined) {
    headers["Content-Type"] = "application/json";
  }

  return send(path, {
    method: options.method ?? "GET",
    headers,
    body: options.body === undefined ? undefined : JSON.stringify(options.body),
    signal: options.signal,
  });
}

async function readJson(response: Response): Promise<unknown> {
  try {
    return await response.json();
  } catch {
    return null;
  }
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === "object" && value !== null && !Array.isArray(value);
}

async function toApiError(response: Response): Promise<ApiError> {
  const body = await readJson(response);
  const error = isRecord(body) && isRecord(body.error) ? body.error : null;

  // Without an envelope (e.g. the proxy could not reach Laravel) the code falls back to server_error.
  const code = (typeof error?.code === "string" ? error.code : "server_error") as ApiErrorCode;
  const message = typeof error?.message === "string" ? error.message : ar.errors.server_error;
  const details = isRecord(error?.details) ? (error.details as ErrorDetails) : null;
  const items = Array.isArray(error?.items) ? (error.items as Record<string, unknown>[]) : null;

  if (response.status === 503 && code === "store_unavailable") {
    return new StoreUnavailableError(response.status, code, message, details, items);
  }

  return new ApiError(response.status, code, message, details, items);
}

/**
 * The only way the frontend talks to the API (rules/03 §4). Same-origin `/api/...` paths only.
 * Resolves to the envelope's `data` (undefined for 204) or throws an ApiError.
 */
export async function apiFetch<T>(path: string, options: ApiOptions = {}): Promise<T> {
  const mutating = (options.method ?? "GET") !== "GET";

  if (mutating && readCsrfToken() === null) {
    await refreshCsrfCookie();
  }

  let response = await request(path, options);

  // 419: the CSRF token expired with the session. Refresh it and try exactly once more.
  if (response.status === 419) {
    await refreshCsrfCookie();
    response = await request(path, options);
  }

  if (response.status === 204) {
    return undefined as T;
  }

  if (!response.ok) {
    const error = await toApiError(response);

    if (error.status === 401 && options.redirectOn401 !== false) {
      onUnauthenticated(surfaceOf(path));
    }

    throw error;
  }

  const body = await readJson(response);

  return (isRecord(body) && "data" in body ? body.data : body) as T;
}
