"use client";

import { keepPreviousData, useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import { apiFetch, apiFetchPage, withQuery } from "@/lib/api/client";
import { queryKeys } from "@/lib/api/keys";
import type { AuditLog, DashboardSettings, SettingsRequest, TenantUser, WorkingHours } from "@/lib/api/types";

import type { ListQuery } from "./list-query";

// Settings, users (08 §5) and the audit log (08 §12).

export function useDashboardSettings() {
  return useQuery({
    queryKey: queryKeys.dashboardSettings,
    queryFn: () => apiFetch<DashboardSettings>("/api/dashboard/settings"),
  });
}

/** Saved settings change the storefront's branding too, so the tenant query is refreshed (applyBranding). */
function useStoreSettings() {
  const queryClient = useQueryClient();

  return (settings: DashboardSettings) => {
    queryClient.setQueryData(queryKeys.dashboardSettings, settings);
    void queryClient.invalidateQueries({ queryKey: queryKeys.tenant });
  };
}

export function useSaveSettings() {
  const store = useStoreSettings();

  return useMutation({
    mutationFn: (body: SettingsRequest) =>
      apiFetch<DashboardSettings>("/api/dashboard/settings", { method: "PUT", body }),
    onSuccess: store,
  });
}

export function useUploadBranding() {
  const store = useStoreSettings();

  return useMutation({
    mutationFn: ({ kind, file }: { kind: "logo" | "favicon"; file: File }) => {
      const form = new FormData();
      form.append("file", file);

      return apiFetch<DashboardSettings>(`/api/dashboard/settings/${kind}`, { method: "POST", body: form });
    },
    onSuccess: store,
  });
}

/** One row per weekday (0 = Sunday) for the working hours editor. */
export type HoursRow = { day: number; open: boolean; from: string; to: string };

export function hoursRows(hours: readonly WorkingHours[]): HoursRow[] {
  return Array.from({ length: 7 }, (_, day) => {
    const set = hours.find((entry) => entry.day === day);

    return set === undefined
      ? { day, open: false, from: "09:00", to: "17:00" }
      : { day, open: true, from: set.from, to: set.to };
  });
}

/** The open days as the API stores them; closed days are left out. */
export function hoursBody(rows: readonly HoursRow[]): WorkingHours[] {
  return rows.filter((row) => row.open).map(({ day, from, to }) => ({ day, from, to }));
}

export function useUsers() {
  return useQuery({
    queryKey: queryKeys.dashboardUsers,
    queryFn: () => apiFetch<TenantUser[]>("/api/dashboard/users"),
  });
}

export function useCreateUser() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (body: { name: string; email: string; password: string }) =>
      apiFetch<TenantUser>("/api/dashboard/users", { method: "POST", body }),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: queryKeys.dashboardUsers }),
  });
}

/** Disable/enable or set a password; 409 cannot_disable_self and last_active_user come back as messages. */
export function useUpdateUser() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: ({ id, ...body }: { id: number; status?: TenantUser["status"]; password?: string }) =>
      apiFetch<TenantUser>(`/api/dashboard/users/${id}`, { method: "PATCH", body }),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: queryKeys.dashboardUsers }),
  });
}

export const AUDIT_LIST_KEYS = ["entity_type", "entity_id", "actor_id", "from", "to", "batch_id"] as const;

export function useAuditLog(query: ListQuery) {
  return useQuery({
    queryKey: queryKeys.dashboardAudit(query),
    queryFn: () => apiFetchPage<AuditLog>(withQuery("/api/dashboard/audit", query)),
    placeholderData: keepPreviousData,
  });
}

export type DiffRow = { field: string; before: string | null; after: string | null; changed: boolean };

function show(value: unknown): string | null {
  if (value === undefined || value === null) {
    return null;
  }

  return typeof value === "object" ? JSON.stringify(value) : String(value);
}

/** Old and new values side by side, one row per field either side has; `changed` marks the differences. */
export function auditDiff(oldValues: AuditLog["old_values"], newValues: AuditLog["new_values"]): DiffRow[] {
  const before = oldValues ?? {};
  const after = newValues ?? {};
  const fields = [...new Set([...Object.keys(before), ...Object.keys(after)])];

  return fields.map((field) => {
    const was = show(before[field]);
    const is = show(after[field]);

    return { field, before: was, after: is, changed: was !== is };
  });
}
