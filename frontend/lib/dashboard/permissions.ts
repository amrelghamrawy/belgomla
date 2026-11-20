"use client";

import type { DashboardMe } from "@/lib/api/types";
import { useDashboardSession } from "@/lib/auth/session";

// BR-T06, D82: what the signed-in user's role may do, as `GET /api/dashboard/me` lists it. The backend enforces it
// (403 forbidden); the dashboard only hides what would be refused.

export type Permission = DashboardMe["permissions"][number];

/** True when the user has any one of the permissions. */
export function can(me: DashboardMe | null | undefined, ...permissions: Permission[]): boolean {
  return me !== null && me !== undefined && permissions.some((permission) => me.permissions.includes(permission));
}

/** `const allowed = useCan(); allowed("orders.manage")`; nothing is allowed while the session loads. */
export function useCan(): (...permissions: Permission[]) => boolean {
  const { data } = useDashboardSession();

  return (...permissions) => can(data, ...permissions);
}

/** D82: the warehouse moves orders only to preparing / out for delivery and never cancels. */
export function useIsWarehouse(): boolean {
  return useDashboardSession().data?.user.role === "warehouse";
}
