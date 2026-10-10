"use client";

import { keepPreviousData, useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import { apiFetch, apiFetchPage, withQuery } from "@/lib/api/client";
import { useIdempotencyKey } from "@/lib/api/idempotency";
import { queryKeys } from "@/lib/api/keys";
import type { Collection, DashboardOrder, DashboardOrderDetail } from "@/lib/api/types";

import type { ListQuery } from "./list-query";
import type { ForwardStatus } from "./order-status";
import { fromPiasters, toPiasters } from "./price-math";

// Orders (08 §9) and collections (08 §10). Transition, cancel, record and void are retry-safe with an
// Idempotency-Key (D55); every order write returns the order detail.

export const ORDER_LIST_KEYS = [
  "status",
  "collection_status",
  "customer_id",
  "from",
  "to",
  "q",
  "stale",
  "sort",
] as const;
export const COLLECTION_LIST_KEYS = ["from", "to", "customer_id", "order"] as const;

export function useOrders(query: ListQuery) {
  return useQuery({
    queryKey: queryKeys.dashboardOrdersList(query),
    queryFn: () => apiFetchPage<DashboardOrder>(withQuery("/api/dashboard/orders", query)),
    placeholderData: keepPreviousData,
  });
}

export function useOrder(number: number) {
  return useQuery({
    queryKey: queryKeys.dashboardOrder(number),
    queryFn: () => apiFetch<DashboardOrderDetail>(`/api/dashboard/orders/${number}`),
  });
}

/** Writes the returned order and refreshes everything an order change moves: lists, stock, reports, bell. */
function useStoreOrder() {
  const queryClient = useQueryClient();

  return (order: DashboardOrderDetail) => {
    queryClient.setQueryData(queryKeys.dashboardOrder(order.number), order);
    for (const key of [
      queryKeys.dashboardOrdersLists,
      queryKeys.dashboardCollectionsLists,
      queryKeys.dashboardOverview,
      ["dashboard", "inventory"],
      ["dashboard", "customers"],
      ["dashboard", "reports"],
    ]) {
      void queryClient.invalidateQueries({ queryKey: key });
    }
  };
}

export type TransitionInput = { to: ForwardStatus; note?: string | null; collect_full_amount?: boolean };

/** BR-O01; `to: delivered` with `collect_full_amount` is the "deliver and collect in full" shortcut (BR-C04). */
export function useTransitionOrder(number: number) {
  const store = useStoreOrder();
  const idempotency = useIdempotencyKey(`transition-${number}`);

  return useMutation({
    mutationFn: (body: TransitionInput) =>
      apiFetch<DashboardOrderDetail>(`/api/dashboard/orders/${number}/transition`, {
        method: "POST",
        body,
        headers: { "Idempotency-Key": idempotency.keyFor(body) },
      }),
    onSuccess: (order) => {
      idempotency.reset();
      store(order);
    },
  });
}

/** BR-O02/O03: cancel with a reason; refused (409 has_collections) while collections exist. */
export function useCancelOrder(number: number) {
  const store = useStoreOrder();
  const idempotency = useIdempotencyKey(`cancel-${number}`);

  return useMutation({
    mutationFn: (reason: string) =>
      apiFetch<DashboardOrderDetail>(`/api/dashboard/orders/${number}/cancel`, {
        method: "POST",
        body: { reason },
        headers: { "Idempotency-Key": idempotency.keyFor({ reason }) },
      }),
    onSuccess: (order) => {
      idempotency.reset();
      store(order);
    },
  });
}

export type DeliveryAddressInput = {
  label: string | null;
  governorate: string;
  city: string;
  address_line: string;
  landmark: string | null;
  name: string | null;
  phone: string | null;
};

/** D9: edit the address snapshot before out for delivery (409 address_locked after). */
export function useUpdateDeliveryAddress(number: number) {
  const store = useStoreOrder();

  return useMutation({
    mutationFn: (body: DeliveryAddressInput) =>
      apiFetch<DashboardOrderDetail>(`/api/dashboard/orders/${number}/delivery-address`, { method: "PUT", body }),
    onSuccess: store,
  });
}

/** Records a cash collection; the order is refetched for its new collected amount and status. */
export function useRecordCollection(number: number) {
  const queryClient = useQueryClient();
  const idempotency = useIdempotencyKey(`collection-${number}`);

  return useMutation({
    mutationFn: (body: { amount: string; note: string | null }) =>
      apiFetch<Collection>(`/api/dashboard/orders/${number}/collections`, {
        method: "POST",
        body,
        headers: { "Idempotency-Key": idempotency.keyFor(body) },
      }),
    onSuccess: () => {
      idempotency.reset();
      void queryClient.invalidateQueries({ queryKey: queryKeys.dashboardOrder(number) });
      void queryClient.invalidateQueries({ queryKey: ["dashboard"] });
    },
  });
}

export function useVoidCollection() {
  const queryClient = useQueryClient();
  const idempotency = useIdempotencyKey("void");

  return useMutation({
    mutationFn: ({ id, reason }: { id: number; reason: string }) =>
      apiFetch<Collection>(`/api/dashboard/collections/${id}/void`, {
        method: "POST",
        body: { reason },
        headers: { "Idempotency-Key": idempotency.keyFor({ id, reason }) },
      }),
    onSuccess: () => {
      idempotency.reset();
      void queryClient.invalidateQueries({ queryKey: ["dashboard"] });
    },
  });
}

export function useCollections(query: ListQuery) {
  return useQuery({
    queryKey: queryKeys.dashboardCollectionsList(query),
    queryFn: () => apiFetchPage<Collection>(withQuery("/api/dashboard/collections", query)),
    placeholderData: keepPreviousData,
  });
}

/** What is left to collect on an order (display only), never below zero. */
export function balanceOf(order: { order_total: string; collected_amount: string }): string {
  const total = toPiasters(order.order_total) ?? 0n;
  const collected = toPiasters(order.collected_amount) ?? 0n;

  return fromPiasters(total > collected ? total - collected : 0n);
}
