"use client";

import { useInfiniteQuery, useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import { apiFetch, apiFetchPage, withQuery, type Page } from "@/lib/api/client";
import { queryKeys } from "@/lib/api/keys";
import type { Order, OrderDetail, OrderStatus } from "@/lib/api/types";

// The customer's orders (08 §3). No in-app notifications for customers (D6): this page is where they see status.

export function useOrdersInfinite(status: OrderStatus | undefined) {
  const params = { status };

  return useInfiniteQuery({
    queryKey: queryKeys.ordersList(params),
    queryFn: ({ pageParam }) => apiFetchPage<Order>(withQuery("/api/store/orders", { ...params, page: pageParam })),
    initialPageParam: 1,
    getNextPageParam: (last: Page<Order>) =>
      last.meta.current_page < last.meta.last_page ? last.meta.current_page + 1 : undefined,
  });
}

export function useOrder(number: number) {
  return useQuery({
    queryKey: queryKeys.order(number),
    queryFn: () => apiFetch<OrderDetail>(`/api/store/orders/${number}`),
  });
}

/** BR-O02: only a pending order, with a reason. */
export function useCancelOrder(number: number) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (reason: string) =>
      apiFetch<OrderDetail>(`/api/store/orders/${number}/cancel`, { method: "POST", body: { reason } }),
    onSuccess: (order) => {
      queryClient.setQueryData(queryKeys.order(number), order);
      void queryClient.invalidateQueries({ queryKey: queryKeys.ordersLists });
    },
  });
}
