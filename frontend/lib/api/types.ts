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
export type CategoryKind = Schemas["CategoryKind"];
export type CategoryIcon = Schemas["CategoryIcon"];
export type CategoryRef = Schemas["CategoryRef"];
export type Category = Schemas["Category"];
export type Facet = Schemas["Facet"];
export type FacetOption = Schemas["FacetOption"];
export type CustomFieldValue = Schemas["CustomFieldValue"];
export type PriceTier = Schemas["PriceTier"];
export type StoreSku = Schemas["StoreSku"];
/** One unit a SKU is sold in (V2 Task 13, D91). */
export type StoreSkuUnit = Schemas["StoreSkuUnit"];
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

// Dashboard (tasks/10)
export type DashboardSettings = Schemas["DashboardSettings"];
export type StoreWhatsApp = Schemas["StoreWhatsApp"];
export type SettingsRequest = Schemas["SettingsRequest"];
export type WorkingHours = Schemas["WorkingHours"];
export type IdName = Schemas["IdName"];
export type ArchiveStatus = Schemas["ArchiveStatus"];
export type ProductStatus = Schemas["ProductStatus"];
export type DashboardCategory = Schemas["DashboardCategory"];
export type Unit = Schemas["Unit"];
export type FieldType = Schemas["FieldType"];
export type FieldDefinition = Schemas["FieldDefinition"];
export type FieldOption = Schemas["FieldOption"];
export type FieldRequest = Schemas["FieldRequest"];
export type DashboardTier = Schemas["DashboardTier"];
export type DashboardSku = Schemas["DashboardSku"];
export type DashboardSkuUnit = Schemas["DashboardSkuUnit"];
export type SkuUnitRequest = Schemas["SkuUnitRequest"];
export type DashboardProduct = Schemas["DashboardProduct"];
export type ProductRelation = Schemas["ProductRelation"];
export type ProductRequest = Schemas["ProductRequest"];
export type TierRequest = Schemas["TierRequest"];
export type PricingRequest = Schemas["PricingRequest"];
export type ProductFilter = Schemas["ProductFilter"];
export type BulkStatusResult = Schemas["BulkStatusResult"];

// Catalog import / export (V2 Task 06)
export type ImportPreview = Schemas["ImportPreview"];
export type ImportRowError = Schemas["ImportRowError"];
export type CatalogImport = Schemas["CatalogImport"];
export type ImportStatus = Schemas["ImportStatus"];
export type QueuedExport = Schemas["QueuedExport"];
export type SpreadsheetFormat = "xlsx" | "csv";
export type SkuPricing = Schemas["SkuPricing"];
export type BulkPricingRequest = Schemas["BulkPricingRequest"];
export type BulkPricingResult = Schemas["BulkPricingResult"];
export type InventoryRow = Schemas["InventoryRow"];
export type BelowReservedDetails = Schemas["BelowReservedDetails"];
export type StockMovement = Schemas["StockMovement"];
export type BulkAdjustItem = Schemas["BulkAdjustItem"];
export type BulkAdjustResult = Schemas["BulkAdjustResult"];
export type CustomerStatus = Schemas["CustomerStatus"];
export type DashboardCustomer = Schemas["DashboardCustomer"];
export type InviteResult = Schemas["InviteResult"];

// Customer groups and negotiated prices (V2 Task 11, BR-PR12..PR14)
export type PriceSource = Schemas["PriceSource"];
export type CustomerPriceSource = Schemas["CustomerPriceSource"];
export type CustomerGroup = Schemas["CustomerGroup"];
export type CustomerGroupRef = Schemas["CustomerGroupRef"];
export type CustomerGroupStatus = Schemas["CustomerGroupStatus"];

// Promotions (V2 Task 12, BR-PR15, D90)
export type Promotion = Schemas["Promotion"];
export type PromotionStatus = Schemas["PromotionStatus"];
export type PromotionSummary = Schemas["PromotionSummary"];
export type PromotionPreview = Schemas["PromotionPreview"];
export type PromotionRequest = Schemas["PromotionRequest"];
export type PromotionPatch = Schemas["PromotionPatch"];
export type CustomerSkuPrice = Schemas["CustomerSkuPrice"];
export type CollectionStatus = Schemas["CollectionStatus"];
export type DashboardOrder = Schemas["DashboardOrder"];
export type DashboardOrderDetail = Schemas["DashboardOrderDetail"];
export type OrderQuote = Schemas["OrderQuote"];
export type OrderQuoteLine = Schemas["OrderQuoteLine"];
export type OrderQuoteRequest = Schemas["OrderQuoteRequest"];
export type MerchantOrderRequest = Schemas["MerchantOrderRequest"];
export type DashboardOrderHistoryEntry = Schemas["DashboardOrderHistoryEntry"];
export type MerchantNotification = Schemas["MerchantNotification"];
export type Collection = Schemas["Collection"];
export type StatementSummary = Schemas["StatementSummary"];
export type CustomerRef = Schemas["CustomerRef"];
export type OverviewReport = Schemas["OverviewReport"];
export type SalesGrouping = Schemas["SalesGrouping"];
export type SalesReport = Schemas["SalesReport"];
export type OutstandingCustomer = Schemas["OutstandingCustomer"];
export type LowStockSku = Schemas["LowStockSku"];
export type AuditLog = Schemas["AuditLog"];

// V2 Task 01 — returns
export type OrderReturn = Schemas["OrderReturn"];
export type OrderReturnLine = Schemas["OrderReturnLine"];
export type ReturnableLine = Schemas["ReturnableLine"];
export type StoreOrderReturn = Schemas["StoreOrderReturn"];

// V2 Task 02 — credit and statement
export type LedgerRow = Schemas["LedgerRow"];
export type LedgerMeta = Schemas["LedgerMeta"];
export type StoreStatementSummary = Schemas["StoreStatementSummary"];
export type CustomerCredit = Schemas["CustomerCredit"];

// V2 Task 09 — order editing and partial delivery
export type OrderItemChange = Schemas["OrderItemChange"];
export type DashboardOrderItemChange = Schemas["DashboardOrderItemChange"];
export type OrderLinesRequest = Schemas["OrderLinesRequest"];

// V2 Task 10 — pre-orders
export type CartLineKind = Schemas["CartLineKind"];
export type PreorderCartItem = Schemas["PreorderCartItem"];
export type StorePreorder = Schemas["StorePreorder"];
export type OrderKind = Schemas["OrderKind"];
export type PreorderState = Schemas["PreorderState"];
export type OrderConversion = Schemas["OrderConversion"];
export type ProductPreorder = Schemas["ProductPreorder"];
export type PreorderDemand = Schemas["PreorderDemand"];
export type PreorderDemandCounts = Schemas["PreorderDemandCounts"];
export type PreorderConversion = Schemas["PreorderConversion"];
export type DashboardPreorder = Schemas["DashboardPreorder"];
