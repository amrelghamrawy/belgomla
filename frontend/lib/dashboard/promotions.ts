"use client";

import { keepPreviousData, useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import { apiFetch, apiFetchPage, withQuery } from "@/lib/api/client";
import { useIdempotencyKey } from "@/lib/api/idempotency";
import { queryKeys } from "@/lib/api/keys";
import type {
  Promotion,
  PromotionPatch,
  PromotionPreview,
  PromotionRequest,
  PromotionStatus,
  ProductFilter,
} from "@/lib/api/types";

import type { ListQuery } from "./list-query";

// Promotions (V2 Task 12, BR-PR15, D90): a time-boxed % off the base price of the products a filter selects.
// `pricing.manage`. Times go to the API as Cairo wall time ("2026-11-01T00:00", D60) and come back ISO-8601 UTC.

export const PROMOTION_LIST_KEYS = ["status"] as const;

export const PROMOTION_STATUSES: PromotionStatus[] = ["draft", "scheduled", "active", "ended", "cancelled"];

/** D90 f: a draft or a promotion that has not started can be edited; anything not over can be cancelled. */
export function isEditable(status: PromotionStatus): boolean {
  return status === "draft" || status === "scheduled";
}

export function isCancellable(status: PromotionStatus): boolean {
  return status !== "ended" && status !== "cancelled";
}

const cairoParts = new Intl.DateTimeFormat("en-GB", {
  timeZone: "Africa/Cairo",
  year: "numeric",
  month: "2-digit",
  day: "2-digit",
  hour: "2-digit",
  minute: "2-digit",
  hourCycle: "h23",
});

/** An ISO-8601 instant as the value of a `datetime-local` input in Cairo time: "2026-10-31T22:00:00Z" → "2026-11-01T00:00". */
export function cairoInputValue(iso: string): string {
  const parts = Object.fromEntries(cairoParts.formatToParts(new Date(iso)).map((part) => [part.type, part.value]));

  return `${parts.year}-${parts.month}-${parts.day}T${parts.hour}:${parts.minute}`;
}

/** A page of promotions, latest start first, filtered by status. */
export function usePromotions(query: ListQuery) {
  return useQuery({
    queryKey: queryKeys.dashboardPromotionsList(query),
    queryFn: () => apiFetchPage<Promotion>(withQuery("/api/dashboard/promotions", query)),
    placeholderData: keepPreviousData,
  });
}

/** A promotion changes store prices, so every save refreshes the promotions and the product lists. */
function useInvalidatePromotions() {
  const queryClient = useQueryClient();

  return () => {
    void queryClient.invalidateQueries({ queryKey: ["dashboard", "promotions"] });
    void queryClient.invalidateQueries({ queryKey: ["store"] });
  };
}

/** D90 d: how many SKUs the selection covers and a sample priced under the %; writes nothing. */
export function usePromotionPreview() {
  return useMutation({
    mutationFn: (body: { filter?: ProductFilter; sku_ids?: number[]; discount_percent?: string }) =>
      apiFetch<PromotionPreview>("/api/dashboard/promotions/preview", { method: "POST", body }),
  });
}

/** Creates a promotion (scheduled, or a draft); retry-safe (D55). */
export function useCreatePromotion() {
  const invalidate = useInvalidatePromotions();
  const idempotency = useIdempotencyKey("promotion");

  return useMutation({
    mutationFn: (body: PromotionRequest) =>
      apiFetch<Promotion>("/api/dashboard/promotions", {
        method: "POST",
        body,
        headers: { "Idempotency-Key": idempotency.keyFor(body) },
      }),
    onSuccess: () => {
      idempotency.reset();
      invalidate();
    },
  });
}

/** Changes a draft or a promotion that has not started (`409 promotion_locked` otherwise). */
export function useUpdatePromotion() {
  const invalidate = useInvalidatePromotions();

  return useMutation({
    mutationFn: ({ id, ...body }: PromotionPatch & { id: number }) =>
      apiFetch<Promotion>(`/api/dashboard/promotions/${id}`, { method: "PATCH", body }),
    onSuccess: invalidate,
  });
}

/** Stops a promotion at once. */
export function useCancelPromotion() {
  const invalidate = useInvalidatePromotions();

  return useMutation({
    mutationFn: (id: number) => apiFetch<Promotion>(`/api/dashboard/promotions/${id}/cancel`, { method: "POST" }),
    onSuccess: invalidate,
  });
}
