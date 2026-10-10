"use client";

import { keepPreviousData, useQuery } from "@tanstack/react-query";

import { apiFetch, apiFetchPage, withQuery } from "@/lib/api/client";
import { queryKeys } from "@/lib/api/keys";
import type { LowStockSku, OutstandingCustomer, OverviewReport, SalesReport } from "@/lib/api/types";

// Reports (08 §11, D5). Export is V2.

/** The overview cards (D5): orders by status, today, stale pending, outstanding total, low-stock count. */
export function useOverviewReport() {
  return useQuery({
    queryKey: queryKeys.dashboardOverview,
    queryFn: () => apiFetch<OverviewReport>("/api/dashboard/reports/overview"),
  });
}

/** Sales buckets by day, week (Saturday start, D58) or month, in Cairo days (D60). */
export function useSalesReport(params: { from?: string; to?: string; group?: string }) {
  return useQuery({
    queryKey: queryKeys.dashboardSales(params),
    queryFn: () => apiFetch<SalesReport>(withQuery("/api/dashboard/reports/sales", params)),
    placeholderData: keepPreviousData,
  });
}

/** Outstanding amount per customer over delivered orders, largest first (D59). */
export function useOutstandingReport(page: number) {
  return useQuery({
    queryKey: queryKeys.dashboardOutstanding(page),
    queryFn: () => apiFetchPage<OutstandingCustomer>(withQuery("/api/dashboard/reports/outstanding", { page })),
    placeholderData: keepPreviousData,
  });
}

/** SKUs at or below MOQ × conversion factor (D36). */
export function useLowStockReport(page: number, perPage = 24) {
  return useQuery({
    queryKey: queryKeys.dashboardLowStock(page, perPage),
    queryFn: () =>
      apiFetchPage<LowStockSku>(withQuery("/api/dashboard/reports/low-stock", { page, per_page: perPage })),
    placeholderData: keepPreviousData,
  });
}
