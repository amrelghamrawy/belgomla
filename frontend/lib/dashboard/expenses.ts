"use client";

import { keepPreviousData, useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import { apiFetch, apiFetchPage, withQuery } from "@/lib/api/client";
import { useIdempotencyKey } from "@/lib/api/idempotency";
import { queryKeys } from "@/lib/api/keys";
import type { Expense, ExpenseCategory, ExpenseCategoryOption, Money, PaginationMeta } from "@/lib/api/types";

import type { ListQuery } from "./list-query";

// Expenses (V2 Task 19, D100): record, list with the live total of the filter, void with a reason.

export const EXPENSE_LIST_KEYS = ["from", "to", "category", "voided"] as const;

export type ExpenseInput = { spent_on: string; amount: string; category: ExpenseCategory; note: string | null };

export function useExpenses(query: ListQuery) {
  return useQuery({
    queryKey: queryKeys.dashboardExpensesList(query),
    queryFn: () =>
      apiFetchPage<Expense, PaginationMeta & { total_amount: Money }>(withQuery("/api/dashboard/expenses", query)),
    placeholderData: keepPreviousData,
  });
}

export function useExpenseCategories() {
  return useQuery({
    queryKey: queryKeys.dashboardExpenseCategories,
    queryFn: () => apiFetch<ExpenseCategoryOption[]>("/api/dashboard/expenses/categories"),
    staleTime: Infinity,
  });
}

/** D100: records an expense; retry-safe (D55). */
export function useRecordExpense() {
  const queryClient = useQueryClient();
  const idempotency = useIdempotencyKey("expense");

  return useMutation({
    mutationFn: (body: ExpenseInput) =>
      apiFetch<Expense>("/api/dashboard/expenses", {
        method: "POST",
        body,
        headers: { "Idempotency-Key": idempotency.keyFor(body) },
      }),
    onSuccess: () => {
      idempotency.reset();
      void queryClient.invalidateQueries({ queryKey: ["dashboard", "expenses"] });
    },
  });
}

/** D100 c: voids an expense with a reason; retry-safe (D55). */
export function useVoidExpense() {
  const queryClient = useQueryClient();
  const idempotency = useIdempotencyKey("void-expense");

  return useMutation({
    mutationFn: ({ id, reason }: { id: number; reason: string }) =>
      apiFetch<Expense>(`/api/dashboard/expenses/${id}/void`, {
        method: "POST",
        body: { reason },
        headers: { "Idempotency-Key": idempotency.keyFor({ id, reason }) },
      }),
    onSuccess: () => {
      idempotency.reset();
      void queryClient.invalidateQueries({ queryKey: ["dashboard", "expenses"] });
    },
  });
}
