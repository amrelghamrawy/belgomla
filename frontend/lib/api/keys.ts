// Every TanStack Query key lives here (rules/03 §4), grouped by surface.
// Keys that take parameters put them last, so invalidating the prefix (e.g. `["store", "orders"]`) hits every page.
export type ListParams = Record<string, unknown>;

export const queryKeys = {
  tenant: ["store", "tenant"] as const,
  customerSession: ["store", "me"] as const,
  dashboardSession: ["dashboard", "me"] as const,

  categories: ["store", "categories"] as const,
  filters: (params: ListParams) => ["store", "filters", params] as const,
  products: (params: ListParams) => ["store", "products", params] as const,
  product: (slug: string) => ["store", "product", slug] as const,
  priceList: (params: ListParams) => ["store", "price-list", params] as const,
  invitation: (token: string) => ["store", "invitation", token] as const,
  addresses: ["store", "addresses"] as const,
  cart: ["store", "cart"] as const,
  ordersLists: ["store", "orders", "list"] as const,
  ordersList: (params: ListParams) => ["store", "orders", "list", params] as const,
  order: (number: number) => ["store", "orders", "detail", number] as const,

  // Dashboard (tasks/10)
  dashboardNotifications: ["dashboard", "notifications"] as const,
  dashboardOverview: ["dashboard", "reports", "overview"] as const,
  dashboardCategories: ["dashboard", "categories"] as const,
  dashboardUnits: ["dashboard", "units"] as const,
  dashboardFields: ["dashboard", "fields"] as const,
  dashboardProductsLists: ["dashboard", "products", "list"] as const,
  dashboardProductsList: (params: ListParams) => ["dashboard", "products", "list", params] as const,
  dashboardProduct: (id: number) => ["dashboard", "products", "detail", id] as const,
};
