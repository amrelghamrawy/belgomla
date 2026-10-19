"use client";

import { keepPreviousData, useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import { apiFetch, apiFetchPage, withQuery } from "@/lib/api/client";
import { queryKeys } from "@/lib/api/keys";
import { useIdempotencyKey } from "@/lib/api/idempotency";
import type {
  AddressRequest,
  Collection,
  CustomerCredit,
  CustomerRef,
  DashboardCustomer,
  InviteResult,
  LedgerMeta,
  LedgerRow,
  StatementSummary,
} from "@/lib/api/types";

import type { ListQuery } from "./list-query";

// Customers (08 §8) with their credit terms, their statement and credits (08 §10, BR-C05..C08).

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
    mutationFn: (body: { name: string; phone: string; addresses?: AddressRequest[] } & CreditTermsInput) =>
      apiFetch<DashboardCustomer>("/api/dashboard/customers", { method: "POST", body }),
    onSuccess: store,
  });
}

export type CustomerAddressInput = AddressRequest & { id?: number };

/** BR-C05, BR-C06: a credit term left out keeps its value (store default on create); null clears it. */
export type CreditTermsInput = { credit_limit?: string | null; payment_terms_days?: number | null };

/** Name and the full address list: rows with an id are edited, new rows added, missing rows archived (D54). */
export function useUpdateCustomer(id: number) {
  const store = useStoreCustomer();

  return useMutation({
    mutationFn: (body: { name: string; addresses: CustomerAddressInput[] } & CreditTermsInput) =>
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

export const STATEMENT_KEYS = ["tab", "from", "to"] as const;

/** BR-C08: one page of the customer's ledger with its opening/closing balance, the customer and the summary. */
export function useStatement(id: number, query: { from?: string; to?: string; page: number }) {
  return useQuery({
    queryKey: queryKeys.dashboardStatement(id, query),
    queryFn: () =>
      apiFetchPage<LedgerRow, LedgerMeta, StatementExtras>(
        withQuery(`/api/dashboard/customers/${id}/statement`, query),
      ),
    placeholderData: keepPreviousData,
  });
}

/** BR-C07: every credit of the customer with what is left. */
export function useCustomerCredits(id: number | undefined) {
  return useQuery({
    queryKey: queryKeys.dashboardCustomerCredits(id ?? 0),
    queryFn: () => apiFetch<CustomerCredit[]>(`/api/dashboard/customers/${id}/credits`),
    enabled: id !== undefined,
  });
}

/** BR-C07: pays part of an order from a credit of the same customer; retry-safe (D55). */
export function useApplyCredit(orderNumber: number) {
  const queryClient = useQueryClient();
  const idempotency = useIdempotencyKey(`credit-${orderNumber}`);

  return useMutation({
    mutationFn: (body: { credit_id: number; amount: string }) =>
      apiFetch<Collection>(`/api/dashboard/orders/${orderNumber}/apply-credit`, {
        method: "POST",
        body,
        headers: { "Idempotency-Key": idempotency.keyFor(body) },
      }),
    onSuccess: () => {
      idempotency.reset();
      void queryClient.invalidateQueries({ queryKey: ["dashboard"] });
    },
  });
}

/** BR-C07: voids an unused credit with the refund that created it; retry-safe (D55). */
export function useVoidCredit() {
  const queryClient = useQueryClient();
  const idempotency = useIdempotencyKey("void-credit");

  return useMutation({
    mutationFn: ({ id, reason }: { id: number; reason: string }) =>
      apiFetch<CustomerCredit>(`/api/dashboard/credits/${id}/void`, {
        method: "POST",
        body: { reason },
        headers: { "Idempotency-Key": idempotency.keyFor({ id, reason }) },
      }),
    onSuccess: () => {
      idempotency.reset();
      void queryClient.invalidateQueries({ queryKey: ["dashboard"] });
    },
  });
}
