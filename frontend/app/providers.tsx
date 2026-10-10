"use client";

import { QueryCache, QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { useEffect, useState, type ReactNode } from "react";
import { toast } from "sonner";

import { DirectionProvider } from "@/components/ui/direction";
import { Toaster } from "@/components/ui/sonner";
import { ApiError, isApiError, StoreUnavailableError } from "@/lib/api/errors";
import { queryKeys } from "@/lib/api/keys";
import { ar } from "@/lib/copy/ar";

const NETWORK_TOAST = "network";

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

        // A read that failed for lack of connection (after its retries): one toast, whose action reloads
        // every query that is in error. Forms show their own errors, so mutations are left alone.
        if (isApiError(error, "network_error")) {
          toast.error(ar.errors.network_error, {
            id: NETWORK_TOAST,
            action: {
              label: ar.retry,
              onClick: () => void queryClient.refetchQueries({ predicate: (query) => query.state.status === "error" }),
            },
          });
        }
      },
    }),
    defaultOptions: {
      queries: { retry: shouldRetry, refetchOnWindowFocus: false },
    },
  });

  return queryClient;
}

/** Tells the shopper when the phone loses or regains its connection. */
export function useConnectionToasts(): void {
  useEffect(() => {
    const offline = () => toast.warning(ar.offline, { id: NETWORK_TOAST });
    const online = () => toast.success(ar.online, { id: NETWORK_TOAST });

    window.addEventListener("offline", offline);
    window.addEventListener("online", online);

    return () => {
      window.removeEventListener("offline", offline);
      window.removeEventListener("online", online);
    };
  }, []);
}

export function Providers({ children }: { children: ReactNode }) {
  const [queryClient] = useState(makeQueryClient);
  useConnectionToasts();

  return (
    <QueryClientProvider client={queryClient}>
      <DirectionProvider dir="rtl">
        {children}
        <Toaster position="top-center" dir="rtl" />
      </DirectionProvider>
    </QueryClientProvider>
  );
}
