"use client";

import { useMutation, useQueryClient } from "@tanstack/react-query";

import { apiFetch } from "@/lib/api/client";
import { queryKeys } from "@/lib/api/keys";
import type { Customer } from "@/lib/api/types";

/** BR-N03: the customer turns the WhatsApp order status messages on or off; the answer is their profile. */
export function useUpdatePreferences() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (body: { order_notifications: boolean }) =>
      apiFetch<Customer>("/api/store/account/preferences", { method: "PATCH", body }),
    onSuccess: (customer) => queryClient.setQueryData(queryKeys.customerSession, customer),
  });
}
