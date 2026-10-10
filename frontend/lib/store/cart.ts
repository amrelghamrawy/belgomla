"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import { apiFetch } from "@/lib/api/client";
import { queryKeys } from "@/lib/api/keys";
import type { Cart } from "@/lib/api/types";

// The server-side cart (BR-CU06). Every write answers with the whole cart priced now (BR-PR04), which
// replaces the cached one: prices are never computed here (rules/03 §4).

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

/** Adds to the line's quantity. A guest gets 401 (redirected to login) or 403 `prices_hidden` (D53). */
export function useAddToCart() {
  return useCartWrite((line: { sku_id: number; quantity: number }) =>
    apiFetch<Cart>("/api/store/cart/items", { method: "POST", body: line }),
  );
}

export function useUpdateCartItem() {
  return useCartWrite(({ sku_id, quantity }: { sku_id: number; quantity: number }) =>
    apiFetch<Cart>(`/api/store/cart/items/${sku_id}`, { method: "PATCH", body: { quantity } }),
  );
}

export function useRemoveCartItem() {
  return useCartWrite((skuId: number) => apiFetch<Cart>(`/api/store/cart/items/${skuId}`, { method: "DELETE" }));
}
