import type {
  Address,
  Cart,
  CartItem,
  Customer,
  Facet,
  OrderDetail,
  PaginationMeta,
  StoreProductDetail,
  StoreSku,
  TenantSettings,
} from "@/lib/api/types";

/** GET /api/store/tenant data for the seeded tenant. */
export function tenantSettings(overrides: Partial<TenantSettings> = {}): TenantSettings {
  return {
    name: "مكتبة النور",
    subdomain: "alnoor",
    logo_url: null,
    favicon_url: null,
    colors: { primary: "#0A5C36", secondary: "#FFFFFF" },
    phone: null,
    whatsapp: null,
    address: null,
    description: null,
    working_hours: [],
    currency: "EGP",
    require_login_for_prices: false,
    require_customer_approval: false,
    mov: { enabled: false, amount: null },
    ...overrides,
  };
}

/** An active customer, as GET /api/store/me returns it. */
export function customer(overrides: Partial<Customer> = {}): Customer {
  return {
    id: 7,
    name: "مكتبة الأمل",
    phone: "+201012345678",
    status: "active",
    status_label: "نشط",
    phone_verified_at: "2026-10-01T10:00:00Z",
    created_at: "2026-10-01T10:00:00Z",
    ...overrides,
  };
}

/** A SKU sold in cartons of 20, MOQ 2, 400 pieces (20 cartons) in stock, base 90.00, tier 10+ → 85.00. */
export function storeSku(overrides: Partial<StoreSku> = {}): StoreSku {
  return {
    id: 501,
    code: "BK-001",
    unit: "كرتونة",
    conversion_factor: 20,
    moq: 2,
    cover_price: "120.00",
    base_price: "90.00",
    discount_percent: "25.00",
    tiers: [{ min_quantity: 10, unit_price: "85.00" }],
    available: 400,
    in_stock: true,
    ...overrides,
  };
}

export function storeProduct(overrides: Partial<StoreProductDetail> = {}): StoreProductDetail {
  return {
    id: 11,
    name: "الأضواء علوم أولى إعدادي",
    slug: "الأضواء-علوم-أولى-إعدادي",
    description: null,
    status: "active",
    main_image_url: null,
    category: { id: 3, name: "أولى إعدادي" },
    custom_fields: [{ key: "grade", label: "السنة الدراسية", value: "أولى إعدادي" }],
    sku: storeSku(),
    gallery: [],
    related: [],
    ...overrides,
  };
}

export function facet(overrides: Partial<Facet> = {}): Facet {
  return {
    key: "grade",
    label: "السنة الدراسية",
    type: "select",
    options: [
      { id: 7, label: "أولى إعدادي", count: 16 },
      { id: 8, label: "ثانية إعدادي", count: 15 },
    ],
    ...overrides,
  };
}

/** A Laravel page of rows (08 §0). */
export function page<T>(data: T[], meta: Partial<PaginationMeta> = {}) {
  return {
    data,
    links: { first: null, last: null, prev: null, next: null },
    meta: {
      current_page: 1,
      from: data.length > 0 ? 1 : null,
      last_page: 1,
      links: [],
      path: "/api/store/products",
      per_page: 24,
      to: data.length,
      total: data.length,
      ...meta,
    },
  };
}

export function cartItem(overrides: Partial<CartItem> = {}): CartItem {
  return {
    sku_id: 501,
    product_name: "الأضواء علوم أولى إعدادي",
    product_slug: "الأضواء-علوم-أولى-إعدادي",
    unit: "كرتونة",
    conversion_factor: 20,
    quantity: 6,
    unit_price: "90.00",
    tier_min_quantity: null,
    line_total: "540.00",
    moq: 2,
    available: 400,
    ...overrides,
  };
}

export function cart(overrides: Partial<Cart> = {}): Cart {
  return {
    items: [cartItem()],
    removed_items: [],
    items_total: "540.00",
    mov: { enabled: true, amount: "500.00", satisfied: true },
    ...overrides,
  };
}

export function address(overrides: Partial<Address> = {}): Address {
  return {
    id: 31,
    label: "المكتبة",
    governorate: "القاهرة",
    city: "مدينة نصر",
    address_line: "12 شارع عباس العقاد",
    landmark: null,
    phone: "+201012345678",
    is_default: true,
    ...overrides,
  };
}

export function order(overrides: Partial<OrderDetail> = {}): OrderDetail {
  return {
    number: 1001,
    status: "pending",
    status_label: "قيد الانتظار",
    placed_at: "2026-10-10T08:00:00Z",
    items_total: "540.00",
    order_total: "540.00",
    collection_status: "unpaid",
    collected_amount: "0.00",
    delivery: {
      name: "مكتبة الأمل",
      phone: "+201012345678",
      address: {
        label: "المكتبة",
        governorate: "القاهرة",
        city: "مدينة نصر",
        address_line: "12 شارع عباس العقاد",
        landmark: null,
      },
    },
    customer_note: null,
    cancel_reason: null,
    cancelled_by: null,
    items: [
      {
        sku_id: 501,
        product_name: "الأضواء علوم أولى إعدادي",
        sku_code: "BK-001",
        sku_name: "الأضواء علوم أولى إعدادي",
        unit: "كرتونة",
        conversion_factor: 20,
        quantity: 6,
        cover_price: "120.00",
        unit_price: "90.00",
        discount_percent: "25.00",
        tier_min_quantity: null,
        line_total: "540.00",
      },
    ],
    history: [{ from: null, to: "pending", at: "2026-10-10T08:00:00Z", by: "customer" }],
    ...overrides,
  };
}

/** A copy without some keys: the API leaves keys out rather than sending null (D1, D21). */
export function omit<T extends object, K extends keyof T>(value: T, ...keys: K[]): Omit<T, K> {
  const copy = { ...value };

  keys.forEach((key) => delete copy[key]);

  return copy;
}

/** A SKU as a guest sees it while the store hides prices (D1). */
export function hiddenPriceSku(): StoreSku {
  return omit(storeSku(), "cover_price", "base_price", "discount_percent", "tiers");
}

/** A coming-soon SKU (D21): no `available`, not in stock. */
export function comingSoonSku(): StoreSku {
  return omit(storeSku({ in_stock: false, coming_soon: true }), "available");
}
