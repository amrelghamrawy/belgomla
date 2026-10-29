"use client";

import { keepPreviousData, useMutation, useQuery } from "@tanstack/react-query";

import { apiDownload, apiFetch, apiFetchPage, withQuery } from "@/lib/api/client";
import { saveDownload } from "@/lib/api/download";
import { queryKeys } from "@/lib/api/keys";
import type {
  BundleReport,
  CollectionsReport,
  CustomerPatternRow,
  CustomerReportView,
  InventoryAnalyticsRow,
  InventorySummary,
  LowStockSku,
  OutstandingCustomer,
  OverviewReport,
  PaginationMeta,
  ProductPerformanceRow,
  ProductReportSort,
  ReportName,
  SalesAnalyticsReport,
  SalesReport,
  SpreadsheetFormat,
} from "@/lib/api/types";

// Reports (08 §11, D5) and the advanced reports of V2 Task 16 (D94).

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

/** BR-P15, D93: bundles delivered in the range and the component base units they used (default the last 30 days). */
export function useBundleReport(params: { from?: string; to?: string }) {
  return useQuery({
    queryKey: queryKeys.dashboardBundleReport(params),
    queryFn: () => apiFetch<BundleReport>(withQuery("/api/dashboard/reports/bundles", params)),
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

// ---- Advanced reports (V2 Task 16, D94): cached 10 minutes on the server, each says when (`generated_at`). ----

/** The page size of the paginated advanced reports: the first page of products is the top 20. */
export const REPORT_PAGE_SIZE = 20;

type ReportParams = Record<string, string | number | undefined>;

/** Gross, discounts, returns and net per bucket, the previous period and the section / customer group split. */
export function useSalesAnalytics(params: ReportParams) {
  return useQuery({
    queryKey: queryKeys.dashboardAdvancedReport("sales-analytics", params),
    queryFn: () => apiFetch<SalesAnalyticsReport>(withQuery("/api/dashboard/reports/sales-analytics", params)),
    placeholderData: keepPreviousData,
  });
}

type ReportRange = { from: string; to: string; generated_at: string };

/** Per SKU: quantities, net revenue, return rate; bundles have their own rows (D93). */
export function useProductReport(params: ReportParams) {
  return useQuery({
    queryKey: queryKeys.dashboardAdvancedReport("products", params),
    queryFn: () =>
      apiFetchPage<ProductPerformanceRow, PaginationMeta, ReportRange & { sort: ProductReportSort }>(
        withQuery("/api/dashboard/reports/products", { per_page: REPORT_PAGE_SIZE, ...params }),
      ),
    placeholderData: keepPreviousData,
  });
}

/** Per customer: the range's orders and revenue, the order rhythm, dormant flag and debts. */
export function useCustomerReport(params: ReportParams) {
  return useQuery({
    queryKey: queryKeys.dashboardAdvancedReport("customers", params),
    queryFn: () =>
      apiFetchPage<
        CustomerPatternRow,
        PaginationMeta,
        ReportRange & { view: CustomerReportView; dormant_days: number }
      >(withQuery("/api/dashboard/reports/customers", { per_page: REPORT_PAGE_SIZE, ...params })),
    placeholderData: keepPreviousData,
  });
}

/** Per SKU: stock and its value at the selling price (D94), units sold, days of cover, dead stock; and a summary. */
export function useInventoryReport(params: ReportParams) {
  return useQuery({
    queryKey: queryKeys.dashboardAdvancedReport("inventory", params),
    queryFn: () =>
      apiFetchPage<
        InventoryAnalyticsRow,
        PaginationMeta,
        ReportRange & { dead_days: number; summary: InventorySummary }
      >(withQuery("/api/dashboard/reports/inventory", { per_page: REPORT_PAGE_SIZE, ...params })),
    placeholderData: keepPreviousData,
  });
}

/** Money collected per bucket next to the net sales delivered, the collection rate and the aging now. */
export function useCollectionsReport(params: ReportParams) {
  return useQuery({
    queryKey: queryKeys.dashboardAdvancedReport("collections", params),
    queryFn: () => apiFetch<CollectionsReport>(withQuery("/api/dashboard/reports/collections", params)),
    placeholderData: keepPreviousData,
  });
}

/** Downloads every row of a report (not one page) with the same filters, as Excel or CSV (D94 k). */
export function useExportReport() {
  return useMutation({
    mutationFn: async ({
      report,
      params,
      format,
    }: {
      report: ReportName;
      params: Record<string, string>;
      format: SpreadsheetFormat;
    }) => {
      const result = await apiDownload(withQuery(`/api/dashboard/reports/${report}/export`, { ...params, format }));

      if ("file" in result) {
        saveDownload(result.file);
      }
    },
  });
}
