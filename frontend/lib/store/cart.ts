"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import { apiFetch, withQuery } from "@/lib/api/client";
import { queryKeys } from "@/lib/api/keys";
import type { Cart, CartLineKind } from "@/lib/api/types";

// The server-side cart (BR-CU06). Every write answers with the whole cart priced now (BR-PR04), which
// replaces the cached one: prices are never computed here (rules/03 §4). A line is an order line or, for a Coming Soon
// product that takes pre-orders, a pre-order line (BR-O14); `kind` left out means an order line.

/** Only for a logged-in active customer: guests have no cart (pass `enabled: false`). */
export function useCart(enabled = true) {
  return useQuery({
    queryKey: queryKeys.cart,
    queryFn: () => apiFetch<Cart>("/api/store/cart"),
    enabled,
  });
}

function useCartWrite<T>(write: (input: T) => Promise<Cart>) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: write,
    onSuccess: (cart) => queryClient.setQueryData(queryKeys.cart, cart),
  });
}

export type CartLineInput = { sku_id: number; quantity: number; kind?: CartLineKind };

/** Adds to the line's quantity. A guest gets 401 (redirected to login) or 403 `prices_hidden` (D53). */
export function useAddToCart() {
  return useCartWrite((line: CartLineInput) => apiFetch<Cart>("/api/store/cart/items", { method: "POST", body: line }));
}

export function useUpdateCartItem() {
  return useCartWrite(({ sku_id, quantity, kind }: CartLineInput) =>
    apiFetch<Cart>(`/api/store/cart/items/${sku_id}`, {
      method: "PATCH",
      body: kind === undefined ? { quantity } : { quantity, kind },
    }),
  );
}

/** Takes an order line out by its SKU id, or a pre-order line with `{ sku_id, kind: "preorder" }`. */
export function useRemoveCartItem() {
  return useCartWrite((line: number | { sku_id: number; kind: CartLineKind }) =>
    typeof line === "number"
      ? apiFetch<Cart>(`/api/store/cart/items/${line}`, { method: "DELETE" })
      : apiFetch<Cart>(withQuery(`/api/store/cart/items/${line.sku_id}`, { kind: line.kind }), { method: "DELETE" }),
  );
}
