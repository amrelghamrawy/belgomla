"use client";

import { useMutation, useQueryClient } from "@tanstack/react-query";
import { apiFetch } from "@/lib/api/client";
import { useIdempotencyKey } from "@/lib/api/idempotency";
import { queryKeys } from "@/lib/api/keys";
import type { OrderDetail } from "@/lib/api/types";

export type CheckoutInput = { address_id: number; customer_note: string | null };

/** What is being confirmed: the cart's order lines (checkout) or its pre-order lines (BR-O14). */
export type CheckoutKind = "order" | "preorder";

const ENDPOINTS: Record<CheckoutKind, string> = {
  order: "/api/store/checkout",
  preorder: "/api/store/checkout/preorder",
};

/**
 * Places the order (BR-PR06), or the pre-order of the cart's pre-order lines (BR-O14), with an `Idempotency-Key`
 * (D55). Retrying the same submission (double tap, a timeout) reuses the key, so the backend replays the first order
 * instead of creating a second one; a changed address or note gets a new key, and so does the next checkout after a
 * success.
 */
export function useCheckout(kind: CheckoutKind = "order") {
  const queryClient = useQueryClient();
  const idempotency = useIdempotencyKey(kind === "order" ? "checkout" : "preorder");

  return useMutation({
    mutationFn: async (input: CheckoutInput) => {
      const placed = await apiFetch<{ order: OrderDetail }>(ENDPOINTS[kind], {
        method: "POST",
        body: input,
        headers: { "Idempotency-Key": idempotency.keyFor(input) },
      });

      return placed.order;
    },
    onSuccess: (order) => {
      idempotency.reset();
      queryClient.setQueryData(queryKeys.order(order.number), order);
      void queryClient.invalidateQueries({ queryKey: queryKeys.cart });
      void queryClient.invalidateQueries({ queryKey: queryKeys.ordersLists });
    },
  });
}
