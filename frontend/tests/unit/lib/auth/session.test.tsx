import { waitFor } from "@testing-library/react";
import { afterEach, describe, expect, it, vi } from "vitest";

import { queryKeys } from "@/lib/api/keys";
import {
  meOrNull,
  safeNext,
  useCustomerLogin,
  useCustomerSession,
  useDashboardLogin,
  useDashboardSession,
  useLogout,
} from "@/lib/auth/session";

import { csrfCookie, envelopeError, json, requestOf, stubFetch } from "../../http";
import { renderHookWithQuery } from "../../render";

const assign = vi.fn();

afterEach(() => {
  assign.mockReset();
});

describe("meOrNull", () => {
  it("returns the session data", async () => {
    stubFetch(json(200, { data: { id: 1 } }));

    await expect(meOrNull("/api/store/me")).resolves.toEqual({ id: 1 });
  });

  it("returns null when nobody is logged in", async () => {
    stubFetch(envelopeError(401, "unauthenticated", "يجب تسجيل الدخول"));

    await expect(meOrNull("/api/dashboard/me")).resolves.toBeNull();
  });

  it("rethrows any other error", async () => {
    stubFetch(envelopeError(403, "tenant_suspended", "تم إيقاف المتجر"));

    await expect(meOrNull("/api/dashboard/me")).rejects.toMatchObject({ code: "tenant_suspended" });
  });
});

describe("session hooks", () => {
  it("useDashboardSession reads the dashboard me endpoint", async () => {
    const me = { user: { name: "مالك" }, tenant: { name: "مكتبة النور" } };
    const fetchMock = stubFetch(json(200, { data: me }));

    const { result } = renderHookWithQuery(() => useDashboardSession());

    await waitFor(() => expect(result.current.data).toEqual(me));
    expect(requestOf(fetchMock, 0).url).toBe("/api/dashboard/me");
  });

  it("useCustomerSession is null for a guest", async () => {
    stubFetch(envelopeError(401, "unauthenticated", "يجب تسجيل الدخول"));

    const { result } = renderHookWithQuery(() => useCustomerSession());

    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(result.current.data).toBeNull();
  });

  it("useDashboardLogin posts the credentials and refreshes the session", async () => {
    const fetchMock = stubFetch(csrfCookie(), json(200, { data: { id: 1 } }));
    const { result, client } = renderHookWithQuery(() => useDashboardLogin());
    client.setQueryData(queryKeys.dashboardSession, null);

    result.current.mutate({ email: "owner@alnoor.test", password: "password" });

    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(requestOf(fetchMock, 1)).toMatchObject({ url: "/api/dashboard/auth/login" });
    expect(requestOf(fetchMock, 1).init.body).toBe('{"email":"owner@alnoor.test","password":"password"}');
    expect(client.getQueryState(queryKeys.dashboardSession)?.isInvalidated).toBe(true);
  });

  it("useCustomerLogin posts to the store login", async () => {
    const fetchMock = stubFetch(csrfCookie(), json(200, { data: { id: 7 } }));
    const { result } = renderHookWithQuery(() => useCustomerLogin());

    result.current.mutate({ phone: "01012345678", password: "password" });

    await waitFor(() => expect(result.current.isSuccess).toBe(true));
    expect(requestOf(fetchMock, 1).url).toBe("/api/store/auth/login");
  });

  it.each([
    ["dashboard", "/dashboard/login"],
    ["store", "/"],
  ] as const)("useLogout(%s) logs out and reloads on %s", async (surface, target) => {
    vi.stubGlobal("location", { ...window.location, assign });
    const fetchMock = stubFetch(csrfCookie(), json(204));
    const { result } = renderHookWithQuery(() => useLogout(surface));

    result.current.mutate();

    await waitFor(() => expect(assign).toHaveBeenCalledWith(target));
    expect(requestOf(fetchMock, 1).url).toBe(`/api/${surface}/auth/logout`);
  });
});

describe("safeNext", () => {
  it.each([
    ["/dashboard/orders?page=2", "/dashboard/orders?page=2"],
    [null, "/dashboard"],
    ["//evil.com", "/dashboard"],
    ["/\\evil.com", "/dashboard"],
    ["https://evil.com", "/dashboard"],
  ])("maps next=%j to %s", (next, expected) => {
    expect(safeNext(next, "/dashboard")).toBe(expected);
  });
});
