"use client";

import { keepPreviousData, useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import { apiFetch, apiFetchPage, apiFetchWith, withQuery } from "@/lib/api/client";
import { useIdempotencyKey } from "@/lib/api/idempotency";
import { queryKeys } from "@/lib/api/keys";
import type { OrderReturn, ReturnableLine } from "@/lib/api/types";

import type { ListQuery } from "./list-query";
import { fromPiasters, toPiasters } from "./price-math";

// Returns (V2 Task 01, 08 §9 Returns): record and void are retry-safe with an Idempotency-Key (D55). A return
// moves stock, the order's totals and maybe a refund, so every write refreshes the whole dashboard.

export const RETURN_LIST_KEYS = ["from", "to", "customer_id", "q"] as const;

export type ReturnInput = {
  reason: string;
  returned_at?: string;
  refund?: boolean;
  refund_to?: "cash" | "instapay" | "wallet" | "bank_transfer" | "credit";
  refund_reference?: string | null;
  items: { order_item_id: number; quantity: number }[];
};

export function useOrderReturns(number: number) {
  return useQuery({
    queryKey: queryKeys.dashboardOrderReturns(number),
    queryFn: () =>
      apiFetchWith<OrderReturn[], { returnable: ReturnableLine[] }>(`/api/dashboard/orders/${number}/returns`),
  });
}

export function useReturns(query: ListQuery) {
  return useQuery({
    queryKey: queryKeys.dashboardReturnsList(query),
    queryFn: () => apiFetchPage<OrderReturn>(withQuery("/api/dashboard/returns", query)),
    placeholderData: keepPreviousData,
  });
}

/** BR-R01..R04: `refund: true` confirms the cash refund the server asked for with `422 refund_required`. */
export function useRecordReturn(number: number) {
  const queryClient = useQueryClient();
  const idempotency = useIdempotencyKey(`return-${number}`);

  return useMutation({
    mutationFn: (body: ReturnInput) =>
      apiFetch<OrderReturn>(`/api/dashboard/orders/${number}/returns`, {
        method: "POST",
        body,
        headers: { "Idempotency-Key": idempotency.keyFor(body) },
      }),
    onSuccess: () => {
      idempotency.reset();
      void queryClient.invalidateQueries({ queryKey: ["dashboard"] });
    },
  });
}

/** BR-R06: 409 return_has_refund while its refund is live, 422 insufficient_stock when the goods are gone (D76). */
export function useVoidReturn() {
  const queryClient = useQueryClient();
  const idempotency = useIdempotencyKey("void-return");

  return useMutation({
    mutationFn: ({ id, reason }: { id: number; reason: string }) =>
      apiFetch<OrderReturn>(`/api/dashboard/returns/${id}/void`, {
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

/** D74: the value of the chosen quantities at each line's order price (display only; the server prices it). */
export function returnValue(lines: ReturnableLine[], quantities: Record<number, number>): string {
  let total = 0n;

  for (const line of lines) {
    total += (toPiasters(line.unit_price) ?? 0n) * BigInt(quantities[line.order_item_id] ?? 0);
  }

  return fromPiasters(total);
}

/** The lines with a quantity above zero, in the request shape. */
export function returnItems(quantities: Record<number, number>): ReturnInput["items"] {
  return Object.entries(quantities)
    .filter(([, quantity]) => quantity > 0)
    .map(([id, quantity]) => ({ order_item_id: Number(id), quantity }));
}
