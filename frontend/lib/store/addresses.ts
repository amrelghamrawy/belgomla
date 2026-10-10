"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import { apiFetch } from "@/lib/api/client";
import { queryKeys } from "@/lib/api/keys";
import type { Address, AddressRequest } from "@/lib/api/types";

// The customer's delivery addresses (BR-CU03). Every change refetches the list: setting a default changes others.

export function useAddresses(enabled = true) {
  return useQuery({
    queryKey: queryKeys.addresses,
    queryFn: () => apiFetch<Address[]>("/api/store/addresses"),
    enabled,
  });
}

export function useSaveAddress() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: ({ id, ...body }: AddressRequest & { id?: number }) =>
      id === undefined
        ? apiFetch<Address>("/api/store/addresses", { method: "POST", body })
        : apiFetch<Address>(`/api/store/addresses/${id}`, { method: "PUT", body }),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: queryKeys.addresses }),
  });
}

export function useDeleteAddress() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (id: number) => apiFetch<void>(`/api/store/addresses/${id}`, { method: "DELETE" }),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: queryKeys.addresses }),
  });
}
