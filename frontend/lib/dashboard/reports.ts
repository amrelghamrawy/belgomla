"use client";

import { useQuery } from "@tanstack/react-query";

import { apiFetch } from "@/lib/api/client";
import { queryKeys } from "@/lib/api/keys";
import type { OverviewReport } from "@/lib/api/types";

/** The overview cards (D5): orders by status, today, stale pending, outstanding total, low-stock count. */
export function useOverviewReport() {
  return useQuery({
    queryKey: queryKeys.dashboardOverview,
    queryFn: () => apiFetch<OverviewReport>("/api/dashboard/reports/overview"),
  });
}
