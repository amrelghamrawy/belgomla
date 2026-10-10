import type { components } from "./schema";

// Friendly names over the generated OpenAPI schema (rules/03 §5). Never hand-write an API shape.
type Schemas = components["schemas"];

export type ErrorCode = Schemas["ErrorCode"];
export type ErrorEnvelope = Schemas["ErrorEnvelope"];
export type Money = Schemas["Money"];
export type TenantSettings = Schemas["TenantSettings"];
export type Customer = Schemas["Customer"];
export type CustomerLoginRequest = Schemas["CustomerLoginRequest"];
export type TenantUser = Schemas["TenantUser"];
export type DashboardLoginRequest = Schemas["DashboardLoginRequest"];
export type DashboardMe = Schemas["DashboardMe"];
