"use client";

import { keepPreviousData, useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import { apiFetch, apiFetchPage, apiFetchWith, withQuery } from "@/lib/api/client";
import { useIdempotencyKey } from "@/lib/api/idempotency";
import { queryKeys } from "@/lib/api/keys";
import type { Collection, DashboardOrder, DashboardOrderDetail, OrderLinesRequest } from "@/lib/api/types";

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
  "kind",
  "sort",
] as const;
export const COLLECTION_LIST_KEYS = ["from", "to", "customer_id", "order", "method"] as const;

/** D103 a: the ways the merchant records money moving; `credit` is the system's. */
export const PAYMENT_METHODS = ["cash", "instapay", "wallet", "bank_transfer"] as const;
export type PaymentMethod = (typeof PAYMENT_METHODS)[number];

export type CollectionInput = { amount: string; note: string | null; method: PaymentMethod; reference: string | null };

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

export type TransitionInput = {
  to: ForwardStatus;
  note?: string | null;
  collect_full_amount?: boolean;
  /** BR-O13: the lines delivered short (a line left out went out in full). */
  delivered_quantities?: { order_item_id: number; quantity: number }[];
};

/**
 * BR-O01; `to: delivered` with `collect_full_amount` is the "deliver and collect in full" shortcut (BR-C04), with
 * `delivered_quantities` a partial delivery (BR-O13).
 */
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

export type OrderDiscountInput = {
  items: { order_item_id: number; type: "amount" | "percent"; value: string }[];
  order: { type: "amount" | "percent"; value: string; reason: string } | null;
};

/**
 * BR-PR08, D85: the whole discount state of a pending or confirmed order (a line left out has none), retry-safe
 * (D55). The answer is the order with its new totals; lists and reports follow its total.
 */
export function useApplyDiscount(number: number) {
  const store = useStoreOrder();
  const idempotency = useIdempotencyKey(`discount-${number}`);

  return useMutation({
    mutationFn: (body: OrderDiscountInput) =>
      apiFetch<DashboardOrderDetail>(`/api/dashboard/orders/${number}/discount`, {
        method: "PUT",
        body,
        headers: { "Idempotency-Key": idempotency.keyFor(body) },
      }),
    onSuccess: (order) => {
      idempotency.reset();
      store(order);
    },
  });
}

/** The minimum order value shortfall an edit left (BR-MOV02): allowed, but the merchant is told. */
export type BelowMov = { required: string; current: string } | null;

/**
 * BR-O12, D87: the order's new lines (the whole list), retry-safe (D55). The answer is the order with its changes and
 * new totals, plus `below_mov`; lists, stock and reports follow it.
 */
export function useEditOrderLines(number: number) {
  const store = useStoreOrder();
  const idempotency = useIdempotencyKey(`lines-${number}`);

  return useMutation({
    mutationFn: async (body: OrderLinesRequest) => {
      const answer = await apiFetchWith<DashboardOrderDetail, { meta: { below_mov: BelowMov } }>(
        `/api/dashboard/orders/${number}/lines`,
        { method: "PUT", body, headers: { "Idempotency-Key": idempotency.keyFor(body) } },
      );

      return { order: answer.data, belowMov: answer.meta.below_mov };
    },
    onSuccess: ({ order }) => {
      idempotency.reset();
      store(order);
    },
  });
}

/** Records a cash collection; the order is refetched for its new collected amount and status. */
export function useRecordCollection(number: number) {
  const queryClient = useQueryClient();
  const idempotency = useIdempotencyKey(`collection-${number}`);

  return useMutation({
    mutationFn: (body: CollectionInput) =>
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

/** What is left to collect on an order: its net total (BR-O08) minus what was collected, never below zero (display only). */
export function balanceOf(order: { net_total: string; collected_amount: string }): string {
  const total = toPiasters(order.net_total) ?? 0n;
  const collected = toPiasters(order.collected_amount) ?? 0n;

  return fromPiasters(total > collected ? total - collected : 0n);
}
