import type { TenantSettings } from "@/lib/api/types";

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
