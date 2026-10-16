import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { render, renderHook } from "@testing-library/react";
import type { ReactElement, ReactNode } from "react";

import { TooltipProvider } from "@/components/ui/tooltip";

/** A QueryClient without retries, so a failed call shows its error at once. */
export function testQueryClient(): QueryClient {
  return new QueryClient({ defaultOptions: { queries: { retry: false }, mutations: { retry: false } } });
}

function wrapperFor(client: QueryClient) {
  return function Wrapper({ children }: { children: ReactNode }) {
    // TooltipProvider as in app/providers.tsx: the sidebar and hint tooltips need it.
    return (
      <QueryClientProvider client={client}>
        <TooltipProvider>{children}</TooltipProvider>
      </QueryClientProvider>
    );
  };
}

export function renderWithQuery(ui: ReactElement, client = testQueryClient()) {
  return { client, ...render(ui, { wrapper: wrapperFor(client) }) };
}

export function renderHookWithQuery<T>(hook: () => T, client = testQueryClient()) {
  return { client, ...renderHook(hook, { wrapper: wrapperFor(client) }) };
}

export { params, pathname, router, searchParams } from "./navigation";
