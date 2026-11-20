"use client";

import { keepPreviousData, useQuery } from "@tanstack/react-query";

import { apiFetchPage, withQuery } from "@/lib/api/client";
import { queryKeys } from "@/lib/api/keys";
import type { LedgerMeta, LedgerRow, StoreStatementSummary } from "@/lib/api/types";

/** BR-C08: the signed-in customer's own ledger, with what they owe, their credit and what is overdue. */
export function useAccountStatement(query: { from?: string; to?: string; page: number }, enabled = true) {
  return useQuery({
    queryKey: queryKeys.accountStatement(query),
    queryFn: () =>
      apiFetchPage<LedgerRow, LedgerMeta, { summary: StoreStatementSummary }>(
        withQuery("/api/store/account/statement", query),
      ),
    placeholderData: keepPreviousData,
    enabled,
  });
}
