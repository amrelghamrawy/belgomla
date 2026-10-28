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
  StoreSkuUnit,
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
    order_notifications: true,
    status: "active",
    status_label: "نشط",
    phone_verified_at: "2026-10-01T10:00:00Z",
    created_at: "2026-10-01T10:00:00Z",
    ...overrides,
  };
}

/**
 * The SKU's one unit (V2 Task 13, D91) mirroring its flat fields, as the API lists a single-unit SKU; a test that wants
 * several units passes `units` itself.
 */
export function withDefaultUnit(sku: Omit<StoreSku, "units"> & { units?: StoreSkuUnit[] }, id = 601): StoreSku {
  const unit: StoreSkuUnit = {
    id,
    unit: sku.unit,
    conversion_factor: sku.conversion_factor,
    moq: sku.moq,
    is_default: true,
    in_stock: sku.in_stock,
  };

  for (const key of ["cover_price", "base_price", "discount_percent", "tiers", "price_source", "promotion"] as const) {
    if (sku[key] !== undefined) {
      Object.assign(unit, { [key]: sku[key] });
    }
  }

  return { ...sku, units: [unit] };
}

/** One more unit of a SKU (D91): a carton of 12 at 1000.00, MOQ 1, in stock. */
export function storeSkuUnit(overrides: Partial<StoreSkuUnit> = {}): StoreSkuUnit {
  return {
    id: 602,
    unit: "كرتونة",
    conversion_factor: 12,
    moq: 1,
    cover_price: null,
    base_price: "1000.00",
    discount_percent: null,
    tiers: [],
    is_default: false,
    in_stock: true,
    ...overrides,
  };
}

/** A SKU sold in cartons of 20, MOQ 2, 400 pieces (20 cartons) in stock, base 90.00, tier 10+ → 85.00. */
export function storeSku(overrides: Partial<StoreSku> = {}): StoreSku {
  const { units, ...flat } = overrides;
  const sku = withDefaultUnit({
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
    ...flat,
  });

  return units === undefined ? sku : { ...sku, units };
}

/** A product without options (one SKU, V1 shape); `skus` follows `sku` unless given (V2 Task 14). */
export function storeProduct(overrides: Partial<StoreProductDetail> = {}): StoreProductDetail {
  const sku = overrides.sku === undefined ? storeSku() : overrides.sku;

  return {
    id: 11,
    name: "الأضواء علوم أولى إعدادي",
    slug: "الأضواء-علوم-أولى-إعدادي",
    description: null,
    status: "active",
    main_image_url: null,
    category: { id: 3, name: "أولى إعدادي", kind: "books" },
    custom_fields: [{ key: "grade", label: "السنة الدراسية", value: "أولى إعدادي" }],
    sku,
    skus: sku === null ? [] : [sku],
    options: [],
    preorder: null,
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
    sku_unit_id: 601,
    product_name: "الأضواء علوم أولى إعدادي",
    variant: null,
    product_slug: "الأضواء-علوم-أولى-إعدادي",
    unit: "كرتونة",
    conversion_factor: 20,
    quantity: 6,
    unit_price: "90.00",
    tier_min_quantity: null,
    price_source: "base",
    promotion: null,
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
    preorders: { items: [], items_total: "0.00" },
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
    kind: "order",
    preorder_state: null,
    converted_at: null,
    status: "pending",
    status_label: "قيد الانتظار",
    placed_at: "2026-10-10T08:00:00Z",
    items_total: "540.00",
    discount: null,
    order_total: "540.00",
    returned_total: "0.00",
    net_total: "540.00",
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
        id: 61,
        sku_id: 501,
        sku_unit_id: 601,
        product_name: "الأضواء علوم أولى إعدادي",
        sku_code: "BK-001",
        sku_name: "الأضواء علوم أولى إعدادي",
        unit: "كرتونة",
        conversion_factor: 20,
        quantity: 6,
        cover_price: "120.00",
        list_unit_price: "90.00",
        manual_discount: null,
        unit_price: "90.00",
        discount_percent: "25.00",
        tier_min_quantity: null,
        price_source: "base",
        line_total: "540.00",
        delivered_quantity: null,
        removed_at: null,
        converted_quantity: 0,
      },
    ],
    changes: [],
    history: [{ from: null, to: "pending", at: "2026-10-10T08:00:00Z", by: "customer" }],
    returns: [],
    converted_from: null,
    conversions: [],
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
  return withDefaultUnit(omit(storeSku(), "cover_price", "base_price", "discount_percent", "tiers"));
}

/** A coming-soon SKU (D21): no `available`, not in stock. */
export function comingSoonSku(): StoreSku {
  return omit(storeSku({ in_stock: false, coming_soon: true }), "available");
}

/**
 * A pen in three colours (V2 Task 14, BR-P14): أزرق 10.00 and أسود 12.00 in stock, أحمر out of stock; each SKU sold by
 * the piece (MOQ 1) with its own unit id (6xx + colour).
 */
export function penProduct(overrides: Partial<StoreProductDetail> = {}): StoreProductDetail {
  const colour = (id: number, value: number, label: string, price: string, available: number) =>
    storeSku({
      id,
      code: `PEN-01-${value}`,
      unit: "قطعة",
      conversion_factor: 1,
      moq: 1,
      cover_price: null,
      base_price: price,
      discount_percent: null,
      tiers: [],
      available,
      in_stock: available > 0,
      label,
      option_value_ids: [value],
      units: [
        storeSkuUnit({
          id: 700 + value,
          unit: "قطعة",
          conversion_factor: 1,
          base_price: price,
          is_default: true,
          in_stock: available > 0,
        }),
      ],
    });
  const skus = [
    colour(81, 1, "أزرق", "10.00", 50),
    colour(82, 2, "أسود", "12.00", 30),
    colour(83, 3, "أحمر", "10.00", 0),
  ];

  return storeProduct({
    id: 40,
    name: "قلم جاف",
    slug: "pen",
    category: { id: 5, name: "أقلام", kind: "stationery" },
    custom_fields: [],
    sku: skus[0],
    skus,
    options: [
      {
        id: 1,
        name: "اللون",
        values: [
          { id: 1, value: "أزرق", image_url: null },
          { id: 2, value: "أسود", image_url: null },
          { id: 3, value: "أحمر", image_url: null },
        ],
      },
    ],
    price_range: { min: "10.00", max: "12.00" },
    ...overrides,
  });
}
