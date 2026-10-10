"use client";

import type { ReactNode } from "react";

import { isApiError, StoreUnavailableError } from "@/lib/api/errors";
import { useTenant } from "@/lib/auth/tenant";
import { ar } from "@/lib/copy/ar";

import { StatusPage } from "./StatusPage";

/**
 * Loads the host's store (and its branding) for every page, and replaces the page when the store is
 * suspended (503 store_unavailable, BR-T07) or the host is not a store (404).
 */
export function TenantGate({ children }: { children: ReactNode }) {
  const { error } = useTenant();

  if (error instanceof StoreUnavailableError) {
    return <StatusPage title={ar.pages.storeUnavailableTitle} body={ar.pages.storeUnavailableBody} />;
  }

  if (isApiError(error, "not_found")) {
    return <StatusPage title={ar.pages.storeNotFoundTitle} body={error.message} />;
  }

  return children;
}
