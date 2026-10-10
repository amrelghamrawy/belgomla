"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

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
