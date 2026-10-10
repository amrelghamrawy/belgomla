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

// Storefront (tasks/9)
export type PaginationMeta = Schemas["PaginationMeta"];
export type Category = Schemas["Category"];
export type Facet = Schemas["Facet"];
export type FacetOption = Schemas["FacetOption"];
export type CustomFieldValue = Schemas["CustomFieldValue"];
export type PriceTier = Schemas["PriceTier"];
export type StoreSku = Schemas["StoreSku"];
export type StoreProduct = Schemas["StoreProduct"];
export type StoreProductDetail = Schemas["StoreProductDetail"];
export type ProductImage = Schemas["ProductImage"];
export type RelatedProduct = Schemas["RelatedProduct"];
export type PriceListRow = Schemas["PriceListRow"];
export type PriceListMeta = Schemas["PriceListMeta"];
export type OtpPurpose = Schemas["OtpPurpose"];
export type OtpSent = Schemas["OtpSent"];
export type RegisterRequest = Schemas["RegisterRequest"];
export type VerifyOtpRequest = Schemas["VerifyOtpRequest"];
export type ResendOtpRequest = Schemas["ResendOtpRequest"];
export type ResetPasswordRequest = Schemas["ResetPasswordRequest"];
export type Invitation = Schemas["Invitation"];
export type Address = Schemas["Address"];
export type AddressRequest = Schemas["AddressRequest"];
export type Cart = Schemas["Cart"];
export type CartItem = Schemas["CartItem"];
export type RemovedCartItem = Schemas["RemovedCartItem"];
export type LineProblem = Schemas["LineProblem"];
export type OrderStatus = Schemas["OrderStatus"];
export type Order = Schemas["Order"];
export type OrderDetail = Schemas["OrderDetail"];
export type OrderItem = Schemas["OrderItem"];
export type OrderHistoryEntry = Schemas["OrderHistoryEntry"];
