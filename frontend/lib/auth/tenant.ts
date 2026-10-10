"use client";

import { useQuery } from "@tanstack/react-query";
import { useEffect } from "react";

import { apiFetch } from "@/lib/api/client";
import { queryKeys } from "@/lib/api/keys";
import type { TenantSettings } from "@/lib/api/types";

import { applyBranding } from "./branding";

export function fetchTenant(): Promise<TenantSettings> {
  return apiFetch<TenantSettings>("/api/store/tenant", { redirectOn401: false });
}

/** The current host's store settings (`GET /api/store/tenant`); applies its branding once loaded. */
export function useTenant() {
  const query = useQuery({
    queryKey: queryKeys.tenant,
    queryFn: fetchTenant,
    staleTime: 5 * 60 * 1000,
  });

  useEffect(() => {
    if (query.data !== undefined) {
      applyBranding(query.data);
    }
  }, [query.data]);

  return query;
}
