"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import { apiFetch, apiFetchPage } from "@/lib/api/client";
import { queryKeys } from "@/lib/api/keys";
import type { MerchantNotification, PaginationMeta } from "@/lib/api/types";

/** How often the bell asks for new orders (tasks/10: unread count polling every 30 s). */
export const NOTIFICATIONS_POLL_MS = 30_000;

/** The newest notifications and the unread count, polled while the dashboard is open. */
export function useNotifications() {
  return useQuery({
    queryKey: queryKeys.dashboardNotifications,
    queryFn: () =>
      apiFetchPage<MerchantNotification, PaginationMeta, { unread_count: number }>(
        "/api/dashboard/notifications?per_page=10",
      ),
    refetchInterval: NOTIFICATIONS_POLL_MS,
  });
}

/** Marks the given notifications (or all of them) read and refreshes the bell. */
export function useMarkNotificationsRead() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (target: { ids: number[] } | { all: true }) =>
      apiFetch<{ marked: number }>("/api/dashboard/notifications/read", { method: "POST", body: target }),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: queryKeys.dashboardNotifications }),
  });
}
