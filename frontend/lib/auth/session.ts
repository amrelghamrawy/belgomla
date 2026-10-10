"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { useRouter } from "next/navigation";
import { useEffect } from "react";

import { apiFetch, loginPaths, type Surface } from "@/lib/api/client";
import { isApiError } from "@/lib/api/errors";
import { queryKeys } from "@/lib/api/keys";
import type { Customer, CustomerLoginRequest, DashboardLoginRequest, DashboardMe, TenantUser } from "@/lib/api/types";

/** A `me` call where 401 means "nobody is logged in" rather than an error. */
export async function meOrNull<T>(path: string): Promise<T | null> {
  try {
    return await apiFetch<T>(path, { redirectOn401: false });
  } catch (error) {
    if (isApiError(error, "unauthenticated")) {
      return null;
    }

    throw error;
  }
}

/** The logged-in merchant user and tenant, or null (`GET /api/dashboard/me`). */
export function useDashboardSession() {
  return useQuery({
    queryKey: queryKeys.dashboardSession,
    queryFn: () => meOrNull<DashboardMe>("/api/dashboard/me"),
  });
}

/** The logged-in customer, or null (`GET /api/store/me`). */
export function useCustomerSession() {
  return useQuery({
    queryKey: queryKeys.customerSession,
    queryFn: () => meOrNull<Customer>("/api/store/me"),
  });
}

/**
 * For pages that need a logged-in customer (cart, checkout, account): sends a guest to the login page with
 * `?next=` back here. `data` is the customer once known; render nothing while it is undefined or null.
 */
export function useRequiredCustomer() {
  const router = useRouter();
  const session = useCustomerSession();

  useEffect(() => {
    if (session.data === null) {
      const next = window.location.pathname + window.location.search;
      router.replace(`${loginPaths.store}?next=${encodeURIComponent(next)}`);
    }
  }, [router, session.data]);

  return session;
}

/**
 * For every dashboard page: sends a guest to the dashboard login with `?next=` back here. `data` is the
 * user and tenant once known; render nothing while it is undefined or null.
 */
export function useRequiredDashboardUser() {
  const router = useRouter();
  const session = useDashboardSession();

  useEffect(() => {
    if (session.data === null) {
      const next = window.location.pathname + window.location.search;
      router.replace(`${loginPaths.dashboard}?next=${encodeURIComponent(next)}`);
    }
  }, [router, session.data]);

  return session;
}

export function useDashboardLogin() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (credentials: DashboardLoginRequest) =>
      apiFetch<TenantUser>("/api/dashboard/auth/login", { method: "POST", body: credentials, redirectOn401: false }),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: queryKeys.dashboardSession }),
  });
}

export function useCustomerLogin() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (credentials: CustomerLoginRequest) =>
      apiFetch<Customer>("/api/store/auth/login", { method: "POST", body: credentials, redirectOn401: false }),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: queryKeys.customerSession }),
  });
}

/**
 * Logs out and reloads the page: routes stay mounted (hidden) under cacheComponents, so a full reload is
 * the only way to drop every bit of the previous user's state (rules/03 §1).
 */
export function useLogout(surface: Surface) {
  return useMutation({
    mutationFn: () => apiFetch<void>(`/api/${surface}/auth/logout`, { method: "POST", redirectOn401: false }),
    onSettled: () => window.location.assign(surface === "dashboard" ? loginPaths.dashboard : "/"),
  });
}

/** A same-site path from `?next=`, or the fallback. Blocks `//evil.com` and absolute URLs. */
export function safeNext(next: string | null, fallback: string): string {
  return next !== null && next.startsWith("/") && !next.startsWith("//") && !next.startsWith("/\\") ? next : fallback;
}
