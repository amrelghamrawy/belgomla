"use client";

import { QueryCache, QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { useState, type ReactNode } from "react";

import { DirectionProvider } from "@/components/ui/direction";
import { Toaster } from "@/components/ui/sonner";
import { ApiError, StoreUnavailableError } from "@/lib/api/errors";
import { queryKeys } from "@/lib/api/keys";

/** Retries only what may succeed on a second try: network errors and 5xx, at most twice. */
export function shouldRetry(failureCount: number, error: unknown): boolean {
  if (error instanceof ApiError && error.status !== 0 && error.status < 500) {
    return false;
  }

  return failureCount < 2;
}

export function makeQueryClient(): QueryClient {
  const queryClient: QueryClient = new QueryClient({
    queryCache: new QueryCache({
      // Any call that finds the store suspended re-checks the tenant, which makes TenantGate show the page.
      onError: (error) => {
        if (error instanceof StoreUnavailableError) {
          void queryClient.invalidateQueries({ queryKey: queryKeys.tenant });
        }
      },
    }),
    defaultOptions: {
      queries: { retry: shouldRetry, refetchOnWindowFocus: false },
    },
  });

  return queryClient;
}

export function Providers({ children }: { children: ReactNode }) {
  const [queryClient] = useState(makeQueryClient);

  return (
    <QueryClientProvider client={queryClient}>
      <DirectionProvider dir="rtl">
        {children}
        <Toaster position="top-center" dir="rtl" />
      </DirectionProvider>
    </QueryClientProvider>
  );
}
