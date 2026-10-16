"use client";

import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import { apiFetch } from "@/lib/api/client";
import { queryKeys } from "@/lib/api/keys";
import type { StoreWhatsApp } from "@/lib/api/types";

// The store's own WhatsApp number (D70, 08 §5).

const PATH = "/api/dashboard/settings/whatsapp";

/** While the QR is shown the status is re-read this often; the backend ends a pairing nobody scans. */
export const PAIRING_POLL_MS = 3000;

/** `poll` re-reads the status every few seconds while pairing (the settings card); the shell banner does not. */
export function useStoreWhatsApp({ poll = false }: { poll?: boolean } = {}) {
  return useQuery({
    queryKey: queryKeys.dashboardStoreWhatsApp,
    queryFn: () => apiFetch<StoreWhatsApp>(PATH),
    refetchInterval: (query) => (poll && query.state.data?.status === "pairing" ? PAIRING_POLL_MS : false),
  });
}

function useStoreAnswer() {
  const queryClient = useQueryClient();

  return (status: StoreWhatsApp) => queryClient.setQueryData(queryKeys.dashboardStoreWhatsApp, status);
}

export function useLinkWhatsApp() {
  const store = useStoreAnswer();

  return useMutation({ mutationFn: () => apiFetch<StoreWhatsApp>(PATH, { method: "POST" }), onSuccess: store });
}

export function useUnlinkWhatsApp() {
  const store = useStoreAnswer();

  return useMutation({ mutationFn: () => apiFetch<StoreWhatsApp>(PATH, { method: "DELETE" }), onSuccess: store });
}

/** The number dropped on its own (or was refused): the merchant should link it again. */
export function needsRelink(status: StoreWhatsApp | undefined): boolean {
  return status?.enabled === true && (status.status === "logged_out" || status.status === "error");
}
