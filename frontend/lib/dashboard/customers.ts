"use client";

import { keepPreviousData, useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import { apiFetch, apiFetchPage, withQuery } from "@/lib/api/client";
import { queryKeys } from "@/lib/api/keys";
import type {
  AddressRequest,
  CustomerRef,
  DashboardCustomer,
  InviteResult,
  PaginationMeta,
  StatementOrder,
  StatementSummary,
} from "@/lib/api/types";

import type { ListQuery } from "./list-query";

// Customers (08 §8) and their statement (08 §10).

export const CUSTOMER_LIST_KEYS = ["q", "status"] as const;

export function useCustomers(query: ListQuery, enabled = true) {
  return useQuery({
    queryKey: queryKeys.dashboardCustomersList(query),
    queryFn: () => apiFetchPage<DashboardCustomer>(withQuery("/api/dashboard/customers", query)),
    placeholderData: keepPreviousData,
    enabled,
  });
}

export function useCustomer(id: number, enabled = true) {
  return useQuery({
    queryKey: queryKeys.dashboardCustomer(id),
    queryFn: () => apiFetch<DashboardCustomer>(`/api/dashboard/customers/${id}`),
    enabled,
  });
}

/** Writes return the customer without recent_orders; keep the ones already shown. */
function useStoreCustomer() {
  const queryClient = useQueryClient();

  return (customer: DashboardCustomer) => {
    queryClient.setQueryData<DashboardCustomer>(queryKeys.dashboardCustomer(customer.id), (current) => ({
      ...customer,
      recent_orders: customer.recent_orders ?? current?.recent_orders,
    }));
    void queryClient.invalidateQueries({ queryKey: queryKeys.dashboardCustomersLists });
  };
}

/** BR-CU08: the merchant creates an invited customer with name, phone and optional addresses. */
export function useCreateCustomer() {
  const store = useStoreCustomer();

  return useMutation({
    mutationFn: (body: { name: string; phone: string; addresses?: AddressRequest[] }) =>
      apiFetch<DashboardCustomer>("/api/dashboard/customers", { method: "POST", body }),
    onSuccess: store,
  });
}

export type CustomerAddressInput = AddressRequest & { id?: number };

/** Name and the full address list: rows with an id are edited, new rows added, missing rows archived (D54). */
export function useUpdateCustomer(id: number) {
  const store = useStoreCustomer();

  return useMutation({
    mutationFn: (body: { name: string; addresses: CustomerAddressInput[] }) =>
      apiFetch<DashboardCustomer>(`/api/dashboard/customers/${id}`, { method: "PUT", body }),
    onSuccess: store,
  });
}

/** Sends or re-sends the 7-day invitation (invited customers only). */
export function useInviteCustomer() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (id: number) => apiFetch<InviteResult>(`/api/dashboard/customers/${id}/invite`, { method: "POST" }),
    onSuccess: (_, id) => queryClient.invalidateQueries({ queryKey: queryKeys.dashboardCustomer(id) }),
  });
}

export type CustomerAction = { action: "approve" } | { action: "unblock" } | { action: "block"; reason: string };

export function useCustomerAction(id: number) {
  const store = useStoreCustomer();

  return useMutation({
    mutationFn: (input: CustomerAction) =>
      apiFetch<DashboardCustomer>(`/api/dashboard/customers/${id}/${input.action}`, {
        method: "POST",
        body: input.action === "block" ? { reason: input.reason } : undefined,
      }),
    onSuccess: store,
  });
}

type StatementExtras = { customer: CustomerRef; summary: StatementSummary };

/** The customer's non-cancelled orders with balances, and the summary (D59: outstanding is delivered only). */
export function useStatement(id: number, page: number) {
  return useQuery({
    queryKey: queryKeys.dashboardStatement(id, page),
    queryFn: () =>
      apiFetchPage<StatementOrder, PaginationMeta, StatementExtras>(
        withQuery(`/api/dashboard/customers/${id}/statement`, { page }),
      ),
    placeholderData: keepPreviousData,
  });
}
