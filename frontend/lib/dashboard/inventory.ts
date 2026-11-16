"use client";

import { keepPreviousData, useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import { apiFetch, apiFetchPage, withQuery } from "@/lib/api/client";
import { useIdempotencyKey } from "@/lib/api/idempotency";
import { type ApiError, isApiError } from "@/lib/api/errors";
import { queryKeys } from "@/lib/api/keys";
import type {
  BelowReservedDetails,
  BulkAdjustItem,
  BulkAdjustResult,
  InventoryRow,
  StockMovement,
} from "@/lib/api/types";

import type { ListQuery } from "./list-query";

// Stock (08 §7): the list, one SKU's row and movements, single and bulk adjustments (BR-INV06, BR-INV08), receiving goods
// and the cost (BR-INV09, D96).

export const INVENTORY_LIST_KEYS = ["q", "low_stock", "sort"] as const;

export function useInventory(query: ListQuery) {
  return useQuery({
    queryKey: queryKeys.dashboardInventoryList(query),
    queryFn: () => apiFetchPage<InventoryRow>(withQuery("/api/dashboard/inventory", query)),
    placeholderData: keepPreviousData,
  });
}

/** One SKU's stock, read fresh when the adjust form opens so `min_allowed` is current (08 §7). */
export function useInventoryRow(skuId: number | null) {
  return useQuery({
    queryKey: queryKeys.dashboardInventoryRow(skuId ?? 0),
    queryFn: () => apiFetch<InventoryRow>(`/api/dashboard/inventory/${skuId}`),
    enabled: skuId !== null,
    staleTime: 0,
  });
}

export function useMovements(skuId: number, page: number) {
  return useQuery({
    queryKey: queryKeys.dashboardMovements(skuId, page),
    queryFn: () => apiFetchPage<StockMovement>(withQuery(`/api/dashboard/inventory/${skuId}/movements`, { page })),
    placeholderData: keepPreviousData,
  });
}

function useInvalidateStock() {
  const queryClient = useQueryClient();

  return () => {
    void queryClient.invalidateQueries({ queryKey: ["dashboard", "inventory"] });
    void queryClient.invalidateQueries({ queryKey: queryKeys.dashboardProductsLists });
    void queryClient.invalidateQueries({ queryKey: queryKeys.dashboardOverview });
  };
}

export function useAdjustStock(skuId: number) {
  const queryClient = useQueryClient();
  const invalidate = useInvalidateStock();

  return useMutation({
    mutationFn: (body: { new_on_hand: number; reason: string }) =>
      apiFetch<InventoryRow>(`/api/dashboard/inventory/${skuId}/adjust`, { method: "POST", body }),
    onSuccess: (row) => {
      queryClient.setQueryData(queryKeys.dashboardInventoryRow(skuId), row);
      invalidate();
    },
  });
}

export type ReceiveStockInput = { quantity: number; unit_cost: string; sku_unit_id: number | null; note: string | null };

/** BR-INV09: goods bought come in at the price paid per the unit named; retry-safe (D55). Returns the row with the new average. */
export function useReceiveStock(skuId: number) {
  const queryClient = useQueryClient();
  const invalidate = useInvalidateStock();
  const idempotency = useIdempotencyKey(`receive-${skuId}`);

  return useMutation({
    mutationFn: (body: ReceiveStockInput) =>
      apiFetch<InventoryRow>(`/api/dashboard/inventory/${skuId}/receive`, {
        method: "POST",
        body,
        headers: { "Idempotency-Key": idempotency.keyFor(body) },
      }),
    onSuccess: (row) => {
      idempotency.reset();
      queryClient.setQueryData(queryKeys.dashboardInventoryRow(skuId), row);
      invalidate();
    },
  });
}

/** D96 d: sets the average cost by hand, per the unit named; no movement. */
export function useSetStockCost(skuId: number) {
  const queryClient = useQueryClient();
  const invalidate = useInvalidateStock();

  return useMutation({
    mutationFn: (body: { unit_cost: string; sku_unit_id: number | null }) =>
      apiFetch<InventoryRow>(`/api/dashboard/inventory/${skuId}/cost`, { method: "PUT", body }),
    onSuccess: (row) => {
      queryClient.setQueryData(queryKeys.dashboardInventoryRow(skuId), row);
      invalidate();
    },
  });
}

/** Applies the valid rows and reports the skipped ones; the body is a bare array (08 §7). */
export function useBulkAdjustStock() {
  const invalidate = useInvalidateStock();

  return useMutation({
    mutationFn: (items: BulkAdjustItem[]) =>
      apiFetch<BulkAdjustResult>("/api/dashboard/inventory/bulk-adjust", { method: "POST", body: items }),
    onSuccess: invalidate,
  });
}

/** The BR-INV08 refusal's details, when the error is one: `{ reserved, min_allowed, orders }`. */
export function belowReservedDetails(error: unknown): (BelowReservedDetails & { error: ApiError }) | null {
  if (!isApiError(error, "inventory_below_reserved") || error.details === null) {
    return null;
  }

  const details = error.details as Partial<BelowReservedDetails>;

  return {
    error,
    reserved: Number(details.reserved ?? 0),
    min_allowed: Number(details.min_allowed ?? 0),
    orders: Array.isArray(details.orders) ? details.orders : [],
  };
}
