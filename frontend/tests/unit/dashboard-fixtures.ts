import type {
  DashboardCategory,
  DashboardMe,
  DashboardProduct,
  DashboardSku,
  FieldDefinition,
  MerchantNotification,
  OrderQuote,
  OrderQuoteLine,
  OverviewReport,
  StoreWhatsApp,
  TenantUser,
  Unit,
} from "@/lib/api/types";

import { queryKeys } from "@/lib/api/keys";

import { json, stubApi } from "./http";
import { testQueryClient } from "./render";
import { page, tenantSettings } from "./fixtures";

// Builders for dashboard API data (tasks/10), shaped like the generated schema so a spec change breaks them.

export function tenantUser(overrides: Partial<TenantUser> = {}): TenantUser {
  return {
    id: 1,
    name: "أحمد",
    email: "owner@alnoor.test",
    role: "owner",
    role_label: "مالك",
    status: "active",
    last_login_at: null,
    ...overrides,
  };
}

/** BR-T06, D82: the backend's role table (TenantUserRole::permissions()). */
export const ROLE_PERMISSIONS: Record<TenantUser["role"], DashboardMe["permissions"]> = {
  owner: [
    "catalog.manage",
    "pricing.manage",
    "inventory.adjust",
    "orders.view",
    "orders.manage",
    "orders.create",
    "orders.override_mov",
    "orders.returns",
    "customers.manage",
    "customers.credit",
    "collections.manage",
    "reports.view",
    "settings.manage",
    "users.manage",
    "audit.view",
  ],
  manager: [
    "catalog.manage",
    "pricing.manage",
    "inventory.adjust",
    "orders.view",
    "orders.manage",
    "orders.create",
    "orders.override_mov",
    "orders.returns",
    "customers.manage",
    "customers.credit",
    "collections.manage",
    "reports.view",
    "audit.view",
  ],
  sales: ["orders.view", "orders.manage", "orders.create", "customers.manage"],
  warehouse: ["inventory.adjust", "orders.view", "orders.manage", "orders.returns"],
  accountant: ["orders.view", "customers.credit", "collections.manage", "reports.view"],
};

export function dashboardMe(overrides: Partial<TenantUser> = {}): DashboardMe {
  const user = tenantUser(overrides);

  return {
    user,
    tenant: { name: "مكتبة النور", subdomain: "alnoor", status: "active", vertical: "school-books" },
    permissions: ROLE_PERMISSIONS[user.role],
  };
}

/** A query client whose dashboard session is already this role's (D82), so permission checks pass at once. */
export function signedInAs(role: TenantUser["role"]) {
  const client = testQueryClient();
  client.setQueryDefaults(queryKeys.dashboardSession, { staleTime: Infinity });
  client.setQueryData(queryKeys.dashboardSession, dashboardMe({ role }));

  return client;
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
    today: { orders: 4, total: "1250.50", returns: "0.00" },
    stale_pending: 1,
    outstanding_total: "3000.00",
    overdue_total: "0.00",
    overdue_customers: 0,
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
    "GET /api/dashboard/settings/whatsapp": json(200, { data: storeWhatsApp() }),
  };
}

/** D70: the store number as GET /api/dashboard/settings/whatsapp returns it; never linked by default. */
export function storeWhatsApp(overrides: Partial<StoreWhatsApp> = {}): StoreWhatsApp {
  return {
    enabled: true,
    status: "disconnected",
    status_label: "غير مربوط",
    phone: null,
    connected_at: null,
    disconnect_reason: null,
    qr: null,
    ...overrides,
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
    icon: null,
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

/** A quoted line of the merchant's order form (BR-O11): 12 cartons of the orderable SKU at the 10+ tier. */
export function orderQuoteLine(overrides: Partial<OrderQuoteLine> = {}): OrderQuoteLine {
  return {
    sku_id: 3,
    product_name: "الأضواء علوم",
    sku_code: "ADW-SCI",
    sku_name: "الأضواء علوم",
    unit: "كرتونة",
    conversion_factor: 20,
    quantity: 12,
    moq: 5,
    available: 20,
    cover_price: null,
    unit_price: "85.00",
    discount_percent: null,
    tier_min_quantity: 10,
    line_total: "1020.00",
    tiers: [{ min_quantity: 10, unit_price: "85.00" }],
    problem: null,
    ...overrides,
  };
}

export function orderQuote(overrides: Partial<OrderQuote> = {}): OrderQuote {
  return {
    lines: [orderQuoteLine()],
    items_total: "1020.00",
    mov: { enabled: false, amount: null, satisfied: true },
    credit: null,
    ...overrides,
  };
}
