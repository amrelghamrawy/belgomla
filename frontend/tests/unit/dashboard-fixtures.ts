import type { DashboardMe, MerchantNotification, OverviewReport, TenantUser } from "@/lib/api/types";

import { json, stubApi } from "./http";
import { page, tenantSettings } from "./fixtures";

// Builders for dashboard API data (tasks/10), shaped like the generated schema so a spec change breaks them.

export function tenantUser(overrides: Partial<TenantUser> = {}): TenantUser {
  return {
    id: 1,
    name: "أحمد",
    email: "owner@alnoor.test",
    role: "owner",
    status: "active",
    last_login_at: null,
    ...overrides,
  };
}

export function dashboardMe(overrides: Partial<TenantUser> = {}): DashboardMe {
  return {
    user: tenantUser(overrides),
    tenant: { name: "مكتبة النور", subdomain: "alnoor", status: "active", vertical: "school-books" },
  };
}

export function notification(overrides: Partial<MerchantNotification> = {}): MerchantNotification {
  return {
    id: 1,
    type: "new_order",
    type_label: "طلب جديد",
    details: { order_number: 1001, customer_name: "مكتبة الأمل", order_total: "450.00" },
    read_at: null,
    created_at: "2026-10-10T08:00:00Z",
    ...overrides,
  };
}

export function overviewReport(overrides: Partial<OverviewReport> = {}): OverviewReport {
  return {
    orders_by_status: { pending: 3, confirmed: 2, preparing: 1, out_for_delivery: 0, delivered: 9, cancelled: 1 },
    today: { orders: 4, total: "1250.50" },
    stale_pending: 1,
    outstanding_total: "3000.00",
    low_stock_count: 2,
    ...overrides,
  };
}

/** The calls every dashboard page makes through the shell: the session, the tenant and the bell. */
export function shellRoutes(notifications: MerchantNotification[] = [], unread = 0) {
  return {
    "GET /api/dashboard/me": json(200, { data: dashboardMe() }),
    "GET /api/store/tenant": json(200, { data: tenantSettings() }),
    "GET /api/dashboard/notifications": json(200, { ...page(notifications), unread_count: unread }),
  };
}

export function stubDashboard(routes: Parameters<typeof stubApi>[0] = {}) {
  return stubApi({ ...shellRoutes(), ...routes });
}
