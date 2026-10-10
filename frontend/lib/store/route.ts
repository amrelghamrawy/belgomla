"use client";

import { useParams } from "next/navigation";

/**
 * A dynamic route segment, decoded (Arabic slugs arrive percent-encoded). Suspends under cacheComponents, so the
 * caller sits inside a <Suspense> boundary.
 */
export function useRouteParam(name: string): string {
  const value = useParams<Record<string, string | string[]>>()[name];
  const raw = Array.isArray(value) ? (value[0] ?? "") : (value ?? "");

  try {
    return decodeURIComponent(raw);
  } catch {
    return raw;
  }
}
