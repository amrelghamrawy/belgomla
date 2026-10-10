"use client";

import { useMutation, useQueryClient } from "@tanstack/react-query";
import { useRef } from "react";

import { apiFetch } from "@/lib/api/client";
import { queryKeys } from "@/lib/api/keys";
import type { OrderDetail } from "@/lib/api/types";

export type CheckoutInput = { address_id: number; customer_note: string | null };

/** 128 random bits as hex. Not crypto.randomUUID(): it exists only on https, and dev/LAN stores run on http. */
export function newIdempotencyKey(): string {
  const bytes = crypto.getRandomValues(new Uint8Array(16));

  return `checkout-${Array.from(bytes, (byte) => byte.toString(16).padStart(2, "0")).join("")}`;
}

/**
 * Places the order (BR-PR06) with an `Idempotency-Key` (D55). Retrying the same submission (double tap, a
 * timeout) reuses the key, so the backend replays the first order instead of creating a second one; a changed
 * address or note gets a new key, and so does the next checkout after a success.
 */
export function useCheckout() {
  const queryClient = useQueryClient();
  const attempt = useRef<{ key: string; body: string } | null>(null);

  return useMutation({
    mutationFn: async (input: CheckoutInput) => {
      const body = JSON.stringify(input);

      if (attempt.current === null || attempt.current.body !== body) {
        attempt.current = { key: newIdempotencyKey(), body };
      }

      const placed = await apiFetch<{ order: OrderDetail }>("/api/store/checkout", {
        method: "POST",
        body: input,
        headers: { "Idempotency-Key": attempt.current.key },
      });

      return placed.order;
    },
    onSuccess: (order) => {
      attempt.current = null;
      queryClient.setQueryData(queryKeys.order(order.number), order);
      void queryClient.invalidateQueries({ queryKey: queryKeys.cart });
      void queryClient.invalidateQueries({ queryKey: queryKeys.ordersLists });
    },
  });
}
