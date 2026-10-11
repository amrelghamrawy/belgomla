import type {
  DashboardCategory,
  DashboardMe,
  DashboardProduct,
  DashboardSku,
  FieldDefinition,
  MerchantNotification,
  OverviewReport,
  TenantUser,
  Unit,
} from "@/lib/api/types";

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

export function unit(overrides: Partial<Unit> = {}): Unit {
  return { id: 1, name: "نسخة", status: "active", ...overrides };
}

export function category(overrides: Partial<DashboardCategory> = {}): DashboardCategory {
  return {
    id: 1,
    name: "المرحلة الابتدائية",
    slug: "primary",
    kind: "books",
    image_url: null,
    parent_id: null,
    sort_order: 0,
    status: "active",
    children: [],
    ...overrides,
  };
}

/** A select field "الصف" with two active options and one archived. */
export function selectField(overrides: Partial<FieldDefinition> = {}): FieldDefinition {
  return {
    id: 1,
    key: "grade",
    label: "الصف",
    type: "select",
    kind: null,
    is_required: true,
    show_on_storefront: true,
    is_filterable: true,
    filter_order: 1,
    sort_order: 1,
    status: "active",
    options: [
      { id: 11, label: "الأول", sort_order: 1, status: "active" },
      { id: 12, label: "الثاني", sort_order: 2, status: "active" },
      { id: 13, label: "القديم", sort_order: 3, status: "archived" },
    ],
    ...overrides,
  };
}

export function dashboardSku(overrides: Partial<DashboardSku> = {}): DashboardSku {
  return {
    id: 5,
    code: "ADW-SCI-1",
    selling_unit: { id: 2, name: "كرتونة" },
    base_unit: { id: 1, name: "نسخة" },
    conversion_factor: 20,
    cover_price: "120.00",
    base_price: "90.00",
    base_price_percent: "25.00",
    moq: 5,
    tiers: [{ min_quantity: 10, unit_price: "84.00", discount_percent: "30.00" }],
    inventory: { on_hand: 400, reserved: 0, available: 400 },
    ...overrides,
  };
}

export function dashboardProduct(overrides: Partial<DashboardProduct> = {}): DashboardProduct {
  return {
    id: 3,
    name: "الأضواء علوم",
    slug: "adwaa-science",
    description: null,
    status: "active",
    category: { id: 1, name: "المرحلة الابتدائية", kind: "books" },
    custom_fields: { grade: 11 },
    sku: dashboardSku(),
    main_image_url: null,
    images: [],
    relations: [],
    created_at: "2026-10-01T08:00:00Z",
    updated_at: "2026-10-01T08:00:00Z",
    ...overrides,
  };
}
