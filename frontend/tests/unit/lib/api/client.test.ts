import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import {
  apiFetch,
  apiFetchPage,
  refreshCsrfCookie,
  setUnauthenticatedHandler,
  surfaceOf,
  withQuery,
} from "@/lib/api/client";
import { ApiError, StoreUnavailableError } from "@/lib/api/errors";

import { csrfCookie, envelopeError, json, requestOf, stubFetch } from "../../http";

const onUnauthenticated = vi.fn();
let previousHandler: ReturnType<typeof setUnauthenticatedHandler>;

beforeEach(() => {
  previousHandler = setUnauthenticatedHandler(onUnauthenticated);
});

afterEach(() => {
  setUnauthenticatedHandler(previousHandler);
  onUnauthenticated.mockReset();
});

describe("apiFetch", () => {
  it("unwraps the data envelope", async () => {
    stubFetch(json(200, { data: { name: "مكتبة النور" } }));

    await expect(apiFetch("/api/store/tenant")).resolves.toEqual({ name: "مكتبة النور" });
  });

  it("keeps a paginated body whole so links and meta stay available", async () => {
    stubFetch(json(200, { items: [1] }));

    await expect(apiFetch("/api/store/x")).resolves.toEqual({ items: [1] });
  });

  it("resolves to undefined on 204", async () => {
    document.cookie = "XSRF-TOKEN=abc; path=/";
    stubFetch(json(204));

    await expect(apiFetch("/api/dashboard/auth/logout", { method: "POST" })).resolves.toBeUndefined();
  });

  it("sends cookies, json headers and the body on same-origin requests", async () => {
    document.cookie = "XSRF-TOKEN=abc; path=/";
    const fetchMock = stubFetch(json(200, { data: {} }));

    await apiFetch("/api/dashboard/auth/login", { method: "POST", body: { email: "a@b.c" } });

    const { url, init, headers } = requestOf(fetchMock, 0);
    expect(url).toBe("/api/dashboard/auth/login");
    expect(init.credentials).toBe("include");
    expect(init.method).toBe("POST");
    expect(init.body).toBe('{"email":"a@b.c"}');
    expect(headers).toMatchObject({
      Accept: "application/json",
      "Content-Type": "application/json",
      "X-Requested-With": "XMLHttpRequest",
    });
  });

  it("sends the decoded XSRF-TOKEN cookie as X-XSRF-TOKEN", async () => {
    document.cookie = "XSRF-TOKEN=a%3Db; path=/";
    const fetchMock = stubFetch(json(200, { data: {} }));

    await apiFetch("/api/store/me");

    expect(requestOf(fetchMock, 0).headers["X-XSRF-TOKEN"]).toBe("a=b");
  });

  it("fetches the CSRF cookie before the first mutating request", async () => {
    const fetchMock = stubFetch(csrfCookie("t1"), json(200, { data: {} }));

    await apiFetch("/api/dashboard/auth/login", { method: "POST", body: {} });

    expect(requestOf(fetchMock, 0).url).toBe("/sanctum/csrf-cookie");
    expect(requestOf(fetchMock, 1).headers["X-XSRF-TOKEN"]).toBe("t1");
  });

  it("does not fetch the CSRF cookie for reads", async () => {
    const fetchMock = stubFetch(json(200, { data: {} }));

    await apiFetch("/api/store/tenant");

    expect(fetchMock).toHaveBeenCalledTimes(1);
  });

  it("refreshes the CSRF cookie and retries once after a 419", async () => {
    document.cookie = "XSRF-TOKEN=old; path=/";
    const fetchMock = stubFetch(
      envelopeError(419, "csrf_token_mismatch", "انتهت صلاحية الجلسة"),
      csrfCookie("new"),
      json(200, { data: { ok: true } }),
    );

    await expect(apiFetch("/api/store/auth/login", { method: "POST", body: {} })).resolves.toEqual({ ok: true });
    expect(requestOf(fetchMock, 1).url).toBe("/sanctum/csrf-cookie");
    expect(requestOf(fetchMock, 2).headers["X-XSRF-TOKEN"]).toBe("new");
  });

  it("gives up after a second 419", async () => {
    document.cookie = "XSRF-TOKEN=old; path=/";
    const fetchMock = stubFetch(
      envelopeError(419, "csrf_token_mismatch", "انتهت صلاحية الجلسة"),
      csrfCookie("new"),
      envelopeError(419, "csrf_token_mismatch", "انتهت صلاحية الجلسة"),
    );

    await expect(apiFetch("/api/store/auth/login", { method: "POST", body: {} })).rejects.toMatchObject({
      status: 419,
      code: "csrf_token_mismatch",
    });
    expect(fetchMock).toHaveBeenCalledTimes(3);
  });

  it("throws an ApiError built from the error envelope", async () => {
    stubFetch(
      json(422, {
        error: {
          code: "validation_failed",
          message: "راجع البيانات",
          details: { password: ["كلمة المرور مطلوب."] },
          items: [{ sku_id: 1 }],
        },
      }),
    );

    const error = await apiFetch("/api/store/x").catch((e: unknown) => e);

    expect(error).toBeInstanceOf(ApiError);
    expect(error).toMatchObject({
      status: 422,
      code: "validation_failed",
      message: "راجع البيانات",
      details: { password: ["كلمة المرور مطلوب."] },
      items: [{ sku_id: 1 }],
    });
    expect((error as ApiError).fieldMessage("password")).toBe("كلمة المرور مطلوب.");
    expect((error as ApiError).fieldMessage("email")).toBeNull();
  });

  it("treats a response without an envelope as server_error", async () => {
    stubFetch(new Response("<html>Bad Gateway</html>", { status: 502 }));

    await expect(apiFetch("/api/store/tenant")).rejects.toMatchObject({ status: 502, code: "server_error" });
  });

  it("turns 503 store_unavailable into StoreUnavailableError", async () => {
    stubFetch(envelopeError(503, "store_unavailable", "المتجر غير متاح"));

    await expect(apiFetch("/api/store/tenant")).rejects.toBeInstanceOf(StoreUnavailableError);
  });

  it("calls the unauthenticated handler with the dashboard surface on 401", async () => {
    stubFetch(envelopeError(401, "unauthenticated", "يجب تسجيل الدخول"));

    await expect(apiFetch("/api/dashboard/orders")).rejects.toMatchObject({ status: 401 });
    expect(onUnauthenticated).toHaveBeenCalledWith("dashboard");
  });

  it("calls the unauthenticated handler with the store surface on 401", async () => {
    stubFetch(envelopeError(401, "unauthenticated", "يجب تسجيل الدخول"));

    await expect(apiFetch("/api/store/cart")).rejects.toMatchObject({ status: 401 });
    expect(onUnauthenticated).toHaveBeenCalledWith("store");
  });

  it("leaves a 401 to the caller when redirectOn401 is false", async () => {
    stubFetch(envelopeError(401, "unauthenticated", "يجب تسجيل الدخول"));

    await expect(apiFetch("/api/dashboard/me", { redirectOn401: false })).rejects.toMatchObject({ status: 401 });
    expect(onUnauthenticated).not.toHaveBeenCalled();
  });

  it("reports a failed connection as network_error", async () => {
    vi.stubGlobal("fetch", vi.fn().mockRejectedValue(new TypeError("Failed to fetch")));

    await expect(apiFetch("/api/store/tenant")).rejects.toMatchObject({ status: 0, code: "network_error" });
  });

  it("lets an aborted request reject with the abort error", async () => {
    vi.stubGlobal("fetch", vi.fn().mockRejectedValue(new DOMException("aborted", "AbortError")));

    await expect(apiFetch("/api/store/tenant")).rejects.toMatchObject({ name: "AbortError" });
  });
});

describe("refreshCsrfCookie", () => {
  it("throws when Sanctum refuses", async () => {
    stubFetch(envelopeError(404, "not_found", "المتجر غير موجود"));

    await expect(refreshCsrfCookie()).rejects.toMatchObject({ status: 404, code: "not_found" });
  });
});

describe("surfaceOf", () => {
  it.each([
    ["/api/dashboard/me", "dashboard"],
    ["/api/store/me", "store"],
    ["/sanctum/csrf-cookie", "store"],
  ])("maps %s to %s", (path, surface) => {
    expect(surfaceOf(path)).toBe(surface);
  });
});

describe("apiFetch headers", () => {
  it("D55 sends extra headers such as Idempotency-Key", async () => {
    const fetchMock = stubFetch(csrfCookie(), json(201, { data: { order: 1 } }));

    await apiFetch("/api/store/checkout", { method: "POST", body: {}, headers: { "Idempotency-Key": "checkout-1" } });

    expect(requestOf(fetchMock, 1).headers["Idempotency-Key"]).toBe("checkout-1");
  });

  it("never lets an extra header replace Accept", async () => {
    const fetchMock = stubFetch(json(200, { data: 1 }));

    await apiFetch("/api/store/x", { headers: { Accept: "text/html" } });

    expect(requestOf(fetchMock, 0).headers.Accept).toBe("application/json");
  });
});

describe("apiFetchPage", () => {
  it("keeps meta next to the rows of a Laravel page", async () => {
    stubFetch(json(200, { data: [{ id: 1 }], links: {}, meta: { current_page: 1, last_page: 3 } }));

    await expect(apiFetchPage("/api/store/products")).resolves.toEqual({
      data: [{ id: 1 }],
      meta: { current_page: 1, last_page: 3 },
    });
  });

  it("keeps the extra top-level keys an endpoint adds, but not links", async () => {
    stubFetch(json(200, { data: [], links: { next: null }, meta: { current_page: 1 }, unread_count: 4 }));

    await expect(
      apiFetchPage<never, { current_page: number }, { unread_count: number }>("/api/dashboard/notifications"),
    ).resolves.toEqual({
      data: [],
      meta: { current_page: 1 },
      unread_count: 4,
    });
  });

  it("treats a body without data and meta as server_error", async () => {
    stubFetch(json(200, { data: { id: 1 } }));

    await expect(apiFetchPage("/api/store/products")).rejects.toMatchObject({ code: "server_error" });
  });

  it("throws the envelope error like apiFetch", async () => {
    stubFetch(envelopeError(422, "validation_failed", "راجع البيانات"));

    await expect(apiFetchPage("/api/store/products?sort=x")).rejects.toMatchObject({
      status: 422,
      code: "validation_failed",
    });
  });
});

describe("withQuery", () => {
  it("BR-P08 writes facets as f[key]=value", () => {
    expect(withQuery("/api/store/products", { category: "prep", f: { grade: ["7"], subject: ["15"] }, page: 2 })).toBe(
      "/api/store/products?category=prep&f%5Bgrade%5D=7&f%5Bsubject%5D=15&page=2",
    );
  });

  it("skips empty values", () => {
    expect(withQuery("/api/store/orders", { status: undefined, q: "", x: null })).toBe("/api/store/orders");
  });

  it("repeats a key for an array", () => {
    expect(withQuery("/x", { id: ["1", "2"] })).toBe("/x?id=1&id=2");
  });
});
