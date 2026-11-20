"use client";

import { keepPreviousData, useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import { apiFetch, apiFetchPage, withQuery } from "@/lib/api/client";
import { queryKeys } from "@/lib/api/keys";
import type { CustomerGroup, CustomerSkuPrice, CustomerStatus } from "@/lib/api/types";

// Customer groups and the prices negotiated per customer (V2 Task 11, BR-PR12..PR14, D89). `pricing.customers`.

export type CustomerGroupInput = { name: string; discount_percent: string; sort_order?: number };

/** Every group, active first, with its customer count. */
export function useCustomerGroups(enabled = true) {
  return useQuery({
    queryKey: queryKeys.dashboardCustomerGroups,
    queryFn: () => apiFetch<CustomerGroup[]>("/api/dashboard/customer-groups"),
    enabled,
  });
}

/** Groups and customers both show a group's name, % and members, so a change refreshes both. */
function useInvalidateGroups() {
  const queryClient = useQueryClient();

  return () => {
    void queryClient.invalidateQueries({ queryKey: queryKeys.dashboardCustomerGroups });
    void queryClient.invalidateQueries({ queryKey: ["dashboard", "customers"] });
  };
}

/** Creates a group (no id) or changes the sent fields of one; `status: "active"` brings an archived group back. */
export function useSaveCustomerGroup() {
  const invalidate = useInvalidateGroups();

  return useMutation({
    mutationFn: ({ id, ...body }: Partial<CustomerGroupInput> & { id?: number; status?: "active" }) =>
      id === undefined
        ? apiFetch<CustomerGroup>("/api/dashboard/customer-groups", { method: "POST", body })
        : apiFetch<CustomerGroup>(`/api/dashboard/customer-groups/${id}`, { method: "PATCH", body }),
    onSuccess: invalidate,
  });
}

/** D89: the discount stops at once; the members keep the group. */
export function useArchiveCustomerGroup() {
  const invalidate = useInvalidateGroups();

  return useMutation({
    mutationFn: (id: number) =>
      apiFetch<CustomerGroup>(`/api/dashboard/customer-groups/${id}/archive`, { method: "POST" }),
    onSuccess: invalidate,
  });
}

export type GroupAssignment =
  { customer_ids: number[] } | { filter: { q?: string; status?: CustomerStatus; group?: number } };

/** Moves the ticked customers, or every customer of the list filter, into a group → how many moved. */
export function useAssignCustomers() {
  const invalidate = useInvalidateGroups();

  return useMutation({
    mutationFn: ({ groupId, ...body }: GroupAssignment & { groupId: number }) =>
      apiFetch<{ assigned: number }>(`/api/dashboard/customer-groups/${groupId}/assign`, { method: "POST", body }),
    onSuccess: invalidate,
  });
}

/** The assignment body for the customers list: the ticked ids, else its filter (q, status, group). */
export function assignmentFor(
  selected: number[],
  query: { q?: string; status?: string; group?: string },
): GroupAssignment {
  if (selected.length > 0) {
    return { customer_ids: selected };
  }

  return {
    filter: {
      ...(query.q ? { q: query.q } : {}),
      ...(query.status ? { status: query.status as CustomerStatus } : {}),
      ...(query.group ? { group: Number(query.group) } : {}),
    },
  };
}

/** BR-PR13: one page of the prices negotiated with a customer; `q` matches the product or SKU code. */
export function useCustomerPrices(customerId: number, query: { q?: string; page?: number }) {
  return useQuery({
    queryKey: queryKeys.dashboardCustomerPrices(customerId, query),
    queryFn: () => apiFetchPage<CustomerSkuPrice>(withQuery(`/api/dashboard/customers/${customerId}/prices`, query)),
    placeholderData: keepPreviousData,
  });
}

/** Sets or replaces the customer's price for a SKU unit (D89: it may be above the normal price; D91: per unit). */
export function useSetCustomerPrice(customerId: number) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: ({ skuUnitId, ...body }: { skuUnitId: number; unit_price: string; note?: string | null }) =>
      apiFetch<CustomerSkuPrice>(`/api/dashboard/customers/${customerId}/prices/${skuUnitId}`, { method: "PUT", body }),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ["dashboard", "customers", "prices", customerId] }),
  });
}

/** Back to the normal price of the SKU unit. */
export function useRemoveCustomerPrice(customerId: number) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (skuUnitId: number) =>
      apiFetch<undefined>(`/api/dashboard/customers/${customerId}/prices/${skuUnitId}`, { method: "DELETE" }),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ["dashboard", "customers", "prices", customerId] }),
  });
}
