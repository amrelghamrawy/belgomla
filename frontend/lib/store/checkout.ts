"use client";

import { useMutation, useQueryClient } from "@tanstack/react-query";
import { apiFetch } from "@/lib/api/client";
import { useIdempotencyKey } from "@/lib/api/idempotency";
import { queryKeys } from "@/lib/api/keys";
import type { OrderDetail } from "@/lib/api/types";

export type CheckoutInput = { address_id: number; customer_note: string | null };

/**
 * Places the order (BR-PR06) with an `Idempotency-Key` (D55). Retrying the same submission (double tap, a
 * timeout) reuses the key, so the backend replays the first order instead of creating a second one; a changed
 * address or note gets a new key, and so does the next checkout after a success.
 */
export function useCheckout() {
  const queryClient = useQueryClient();
  const idempotency = useIdempotencyKey("checkout");

  return useMutation({
    mutationFn: async (input: CheckoutInput) => {
      const placed = await apiFetch<{ order: OrderDetail }>("/api/store/checkout", {
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
