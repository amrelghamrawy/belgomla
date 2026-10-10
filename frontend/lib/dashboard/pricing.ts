"use client";

import { useMutation, useQueryClient } from "@tanstack/react-query";

import { apiFetch } from "@/lib/api/client";
import { queryKeys } from "@/lib/api/keys";
import type { BulkPricingRequest, BulkPricingResult, SkuPricing } from "@/lib/api/types";

// Pricing actions beyond the product form (08 §6, BR-PR10/PR11).

/** Runs the bulk selection and checks without writing: `{ affected, skipped_no_cover_price }`. */
export function useBulkPricingPreview() {
  return useMutation({
    mutationFn: (body: BulkPricingRequest) =>
      apiFetch<BulkPricingResult>("/api/dashboard/pricing/bulk/preview", { method: "POST", body }),
  });
}

/** Applies the bulk pricing in one transaction and audit batch. */
export function useBulkPricing() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (body: BulkPricingRequest) =>
      apiFetch<BulkPricingResult>("/api/dashboard/pricing/bulk", { method: "POST", body }),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ["dashboard", "products"] }),
  });
}

/** Recomputes percentage-based prices from the current cover price (audited); absolute prices stay. */
export function useRecomputePricing(productId: number) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: () =>
      apiFetch<SkuPricing>(`/api/dashboard/products/${productId}/pricing/recompute`, { method: "POST" }),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: queryKeys.dashboardProduct(productId) });
      void queryClient.invalidateQueries({ queryKey: queryKeys.dashboardProductsLists });
    },
  });
}
