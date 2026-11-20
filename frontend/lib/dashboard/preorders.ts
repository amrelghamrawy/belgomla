"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import { apiFetch } from "@/lib/api/client";
import { useIdempotencyKey } from "@/lib/api/idempotency";
import { queryKeys } from "@/lib/api/keys";
import type { PreorderConversion, PreorderDemand } from "@/lib/api/types";

// Pre-orders on the dashboard (BR-O14, D88, 08 §9): a product's open demand, and converting its pre-orders into
// normal orders once it is active and stocked (FIFO, as far as the stock goes). Converting is retry-safe (D55).

export function usePreorderDemand(productId: number, enabled = true) {
  return useQuery({
    queryKey: queryKeys.dashboardPreorderDemand(productId),
    queryFn: () => apiFetch<PreorderDemand>(`/api/dashboard/products/${productId}/preorder-demand`),
    enabled,
  });
}

export function useConvertPreorders(productId: number) {
  const queryClient = useQueryClient();
  const idempotency = useIdempotencyKey(`convert-preorders-${productId}`);

  return useMutation({
    mutationFn: () =>
      apiFetch<PreorderConversion>(`/api/dashboard/products/${productId}/convert-preorders`, {
        method: "POST",
        headers: { "Idempotency-Key": idempotency.keyFor({ productId }) },
      }),
    onSuccess: () => {
      idempotency.reset();
      void queryClient.invalidateQueries({ queryKey: queryKeys.dashboardPreorderDemand(productId) });
      void queryClient.invalidateQueries({ queryKey: queryKeys.dashboardPreordersLists });
      void queryClient.invalidateQueries({ queryKey: queryKeys.dashboardOrdersLists });
      void queryClient.invalidateQueries({ queryKey: queryKeys.dashboardProduct(productId) });
      void queryClient.invalidateQueries({ queryKey: queryKeys.dashboardInventoryLists });
    },
  });
}
