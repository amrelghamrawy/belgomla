import type {
  DashboardCategory,
  DashboardMe,
  DashboardProduct,
  DashboardSku,
  DashboardSkuUnit,
  DashboardTier,
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
import { page, section, tenantSettings } from "./fixtures";

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
    "pricing.customers",
    "inventory.adjust",
    "orders.view",
    "orders.manage",
    "orders.create",
    "orders.override_mov",
    "orders.edit",
    "orders.discount",
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
    "pricing.customers",
    "inventory.adjust",
    "orders.view",
    "orders.manage",
    "orders.create",
    "orders.override_mov",
    "orders.edit",
    "orders.discount",
    "orders.returns",
    "customers.manage",
    "customers.credit",
    "collections.manage",
    "reports.view",
    "audit.view",
  ],
  sales: ["orders.view", "orders.manage", "orders.create", "orders.edit", "orders.discount", "customers.manage"],
  warehouse: ["inventory.adjust", "orders.view", "orders.manage", "orders.returns"],
  accountant: ["orders.view", "customers.credit", "collections.manage", "reports.view"],
};

export function dashboardMe(overrides: Partial<TenantUser> = {}): DashboardMe {
  const user = tenantUser(overrides);

  return {
    user,
    tenant: {
      name: "مكتبة النور",
      subdomain: "alnoor",
      status: "active",
      vertical: { key: "libraries", name: "مكتبات" },
    },
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
    section: section("books"),
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
    section: null,
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

/**
 * A SKU sold in one unit (V2 Task 13, D91): the default unit mirrors the flat fields and the tiers given, and has the
 * SKU's id (so a line picked from it is `line-{sku id}`); a test that wants several units passes `units` itself.
 */
export function dashboardSku(overrides: Partial<DashboardSku> & { tiers?: DashboardTier[] } = {}): DashboardSku {
  const { tiers = [{ min_quantity: 10, unit_price: "84.00", discount_percent: "30.00" }], units, ...flat } = overrides;
  const sku: DashboardSku = {
    id: 5,
    code: "ADW-SCI-1",
    selling_unit: { id: 2, name: "كرتونة" },
    base_unit: { id: 1, name: "نسخة" },
    conversion_factor: 20,
    cover_price: "120.00",
    base_price: "90.00",
    base_price_percent: "25.00",
    moq: 5,
    inventory: { on_hand: 400, reserved: 0, available: 400 },
    ...flat,
  };

  return {
    ...sku,
    units: units ?? [
      {
        id: sku.id,
        unit: sku.selling_unit,
        conversion_factor: sku.conversion_factor ?? 1,
        cover_price: sku.cover_price,
        base_price: sku.base_price ?? "0.00",
        base_price_percent: sku.base_price_percent,
        moq: sku.moq ?? 1,
        is_default: true,
        tiers,
      },
    ],
  };
}

/** A further unit of a SKU (D91): a carton of 12. */
export function dashboardSkuUnit(overrides: Partial<DashboardSkuUnit> = {}): DashboardSkuUnit {
  return {
    id: 9,
    unit: { id: 4, name: "كرتونة 12" },
    conversion_factor: 12,
    cover_price: null,
    base_price: "1000.00",
    base_price_percent: null,
    moq: 1,
    is_default: false,
    tiers: [],
    ...overrides,
  };
}

/** A product without options (one SKU); `skus` follows `sku` unless given (V2 Task 14). */
export function dashboardProduct(overrides: Partial<DashboardProduct> = {}): DashboardProduct {
  const sku = overrides.sku === undefined ? dashboardSku() : overrides.sku;

  return {
    id: 3,
    name: "الأضواء علوم",
    slug: "adwaa-science",
    description: null,
    status: "active",
    type: "simple",
    category: { id: 1, name: "المرحلة الابتدائية", kind: "books", section: section("books") },
    custom_fields: { grade: 11 },
    preorder: { enabled: false, expected_at: null, limit: null },
    sku,
    skus: sku === null ? [] : [sku],
    options: [],
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
    sku_unit_id: overrides.sku_id ?? 3,
    product_name: "الأضواء علوم",
    sku_code: "ADW-SCI",
    sku_name: "الأضواء علوم",
    unit: "كرتونة",
    conversion_factor: 20,
    quantity: 12,
    moq: 5,
    available: 20,
    is_bundle: false,
    cover_price: null,
    unit_price: "85.00",
    discount_percent: null,
    tier_min_quantity: 10,
    price_source: "tier",
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

/**
 * A pen with one option «اللون» (أزرق, أسود) and a SKU per colour (V2 Task 14, BR-P14), each sold by the carton at
 * 90.00 as in dashboardSku(); the black one is inactive.
 */
export function variantProduct(overrides: Partial<DashboardProduct> = {}): DashboardProduct {
  const blue = {
    ...dashboardSku({ id: 21, code: "PEN-01-1", tiers: [] }),
    label: "أزرق",
    status: "active" as const,
    sort_order: 0,
    option_values: ["أزرق"],
  };
  const black = {
    ...dashboardSku({ id: 22, code: "PEN-01-2", tiers: [] }),
    label: "أسود",
    status: "inactive" as const,
    sort_order: 1,
    option_values: ["أسود"],
  };

  return dashboardProduct({
    id: 4,
    name: "قلم جاف",
    sku: blue,
    skus: [blue, black],
    options: [
      {
        id: 31,
        name: "اللون",
        sort_order: 0,
        values: [
          { id: 41, value: "أزرق", sort_order: 0, image_id: null, image_url: null },
          { id: 42, value: "أسود", sort_order: 1, image_id: 7, image_url: "/storage/p/7.jpg" },
        ],
      },
    ],
    images: [{ id: 7, url: "/storage/p/7.jpg", is_main: true, sort_order: 0 }],
    ...overrides,
  });
}

/**
 * A bundle (V2 Task 15, BR-P15, D93) «طقم أولى إعدادي», code SET-1, sold by the «طقم» (unit 7), MOQ 1, inactive: 1 ×
 * ADW-SCI-1 (unit 5, 90.00) and 2 × piece of a pen (unit 21, 10.00), sum 110.00 at 10 % off; 3 can be made now.
 */
export function bundleDashboardProduct(overrides: Partial<DashboardProduct> = {}): DashboardProduct {
  const sku = dashboardSku({
    id: 50,
    code: "SET-1",
    selling_unit: { id: 7, name: "طقم" },
    base_unit: { id: 7, name: "طقم" },
    conversion_factor: 1,
    cover_price: null,
    base_price: "99.00",
    base_price_percent: null,
    moq: 1,
    tiers: [],
  });

  return dashboardProduct({
    id: 30,
    name: "طقم أولى إعدادي",
    type: "bundle",
    status: "inactive",
    custom_fields: { grade: 11 },
    sku,
    bundle: {
      pricing: { mode: "percent_off_sum", value: "10.00" },
      components: [
        {
          id: 1,
          sku_unit_id: 5,
          sku_id: 5,
          product_id: 3,
          product_name: "الأضواء علوم",
          sku_code: "ADW-SCI-1",
          label: null,
          unit: "كرتونة",
          conversion_factor: 20,
          quantity: 1,
          base_price: "90.00",
          available: 400,
          orderable: true,
        },
        {
          id: 2,
          sku_unit_id: 21,
          sku_id: 21,
          product_id: 4,
          product_name: "قلم جاف",
          sku_code: "PEN-01-1",
          label: "أزرق",
          unit: "قطعة",
          conversion_factor: 1,
          quantity: 2,
          base_price: "10.00",
          available: 6,
          orderable: false,
        },
      ],
      components_total: "110.00",
      availability: 3,
    },
    ...overrides,
  });
}
