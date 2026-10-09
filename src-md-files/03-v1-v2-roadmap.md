# BelGomla E-commerce — V1 / V2 / Later Roadmap

## V1 — Core Wholesale Commerce

### Goal

Launch a usable tenant-specific wholesale store where merchants can list products, define wholesale pricing, receive orders, deliver them, and track collection.

### V1 Modules

#### 1. Tenancy

- Tenant entity and status (Active / Suspended).
- Tenant subdomain.
- Tenant resolution from host.
- Strict tenant isolation.
- Tenant settings (branding, contact, Minimum Order Value).
- Tenant users (single `owner` role).
- CLI command to create / suspend tenants.

#### 2. Storefront

- Arabic.
- RTL.
- Mobile-first.
- Tenant branding.
- Home/store landing.
- Categories.
- Product listing.
- Product details.
- Search/filter basics.
- Customer registration / login (phone + password + OTP).
- Invitation activation page.
- Storefront filters by filterable custom fields.
- Cart.
- Checkout.
- Order confirmation.
- Customer orders and customer cancellation (Pending only).
- Customer account and addresses.

#### 3. Catalog

- Categories (unlimited nesting).
- Products.
- Tenant-defined custom product fields with vertical templates (school-books, stationery, hardware).
- Faceted storefront filtering and search over custom fields.
- Product relations (revision_of, companion).
- Duplicate product and bulk status change by filter.
- SKU (one per product in V1 UI).
- Product status.
- Product images.
- Tenant-defined units.
- One selling unit per SKU with conversion factor to base unit.

#### 4. Wholesale Pricing

- Mandatory base price per SKU.
- Optional cover price per SKU, with prices enterable as % off cover price.
- Bulk pricing by filter (per publisher / grade / year).
- Printable price list per facet selection.
- Optional quantity pricing tiers per SKU.
- Independent tiers per SKU.
- Integer quantities.
- MOQ per SKU.
- Checkout price recalculation.
- Order price snapshots.

#### 5. Inventory

- On Hand / Reserved / Available in base unit.
- Stock adjustments with reason.
- Inventory movements (opening, adjustment, sale).
- Reservation at order creation, release on cancel, deduction on delivery.
- No backorders.

#### 6. Orders

- Pending / Confirmed / Preparing / Out for Delivery / Delivered / Cancelled.
- Customer cancel (Pending) and merchant cancel (any status before Delivered).
- Order status history.
- Order, item and address snapshots.
- Per-tenant order numbers.
- Immutable items after creation.

#### 7. Customers

- Tenant-specific customers.
- Phone + password auth with OTP verification (WhatsApp, SMS fallback).
- Merchant-created customers and invitations.
- Customer profile and status.
- Addresses.
- Orders.

#### 8. Merchant Delivery

- Merchant-managed delivery.
- Delivery address and customer phone snapshot on the order.
- No shipping provider integration, no delivery fees.

#### 9. Collections

- Cash collection records (amount, date, recorded by, void).
- Derived status: Unpaid / Partially Collected / Collected.

#### 10. Dashboard

Tenant-only dashboard containing:

- Products.
- Categories.
- Pricing.
- Inventory.
- Orders.
- Customers.
- Collections.
- Basic reports (see `06-open-decisions.md` for the exact list).
- Settings.

#### 11. Audit

Append-only audit of important business changes.

#### 12. Notifications / Messaging

- In-app merchant notifications.
- Outbound messaging service with WhatsApp provider and SMS provider, used only for OTP and invitations.

### Explicit V1 Non-Goals

- Platform Admin UI.
- Marketplace.
- Online payment.
- Shipping integrations and delivery fees.
- Email.
- WhatsApp / SMS order notifications (only OTP and invitations in V1).
- Subscription billing.
- Manual discounts.
- Customer-specific prices.
- Product variants in the UI.
- Multiple selling units per SKU.
- Roles / permissions.
- Custom domains.
- Backorders.
- Returns (المرتجع) → V2, first feature.
- Order editing / partial fulfilment.
- Advanced accounting.

---

# V2 — Commercial Expansion

V2 should focus on increasing merchant flexibility and operational capability after the core wholesale flow is stable.

## V2 Candidates

### Catalog

- Product variants (color/size) exposed in the UI.
- Multiple selling units per SKU.
- Bulk catalog import/export.

### Pricing

- Manual order discount.
- Item-level discount.
- Customer-specific pricing.
- Negotiated customer pricing.
- Promotional pricing.
- Taxes / VAT handling if required.

### Orders (highest V2 priority for school books)

- **Returns (المرتجع)**: return orders, return movements, credit against outstanding balance. Confirmed V2 (not V1); planned as the first V2 feature because end-of-season returns are standard in the school-book trade.
- **Pre-orders** on Coming Soon products, converted to normal orders when stock arrives.
- Order editing and partial fulfilment.
- Automatic expiry of unconfirmed orders.
- Product bundles / full-grade sets.

### Customer Management

- Customer groups.
- Wholesale customer levels.
- Credit limits and payment terms (آجل up to N days), as offered by Egyptian B2B platforms.
- Customer account statement (كشف حساب).
- Customer-specific catalogs.

### Payments

- Online payment gateways.
- Bank transfer workflows.
- InstaPay or wallet workflows where appropriate.

### Delivery

- Shipping company integrations.
- Delivery fees.
- Delivery zones.
- Driver assignment.
- Delivery tracking.

### Communication

- WhatsApp / SMS order status notifications (reusing the V1 messaging service).
- Telegram.
- Richer order notifications.

### Merchant Operations

- Staff accounts with roles and permissions.
- More detailed operational workflows.

### Subscription

- Tenant subscription plans.
- Billing.
- Renewals.
- Subscription status automation.

### Reporting

- Sales analytics.
- Product performance.
- Customer purchasing patterns.
- Inventory analytics.
- Collection reports.

---

# Later — Scale / Advanced Capabilities

These should not be designed into V1 unless a concrete requirement appears.

## Platform Capabilities

- Platform Admin UI.
- Merchant self-onboarding.
- Merchant approval.
- Platform-wide tenant management.
- Subscription management.
- Platform billing.
- Platform analytics.

## Marketplace

Only if the business direction changes:

- Cross-store search.
- Merchant discovery.
- Product comparison.
- Marketplace categories.
- Cross-store shopping.

## Advanced Commerce

- Backorders.
- Advanced promotions.
- Coupons.
- Loyalty.
- Reviews and ratings.
- Recommendations.
- Advanced customer segmentation.

## Advanced Operations

- Warehouse management.
- Multiple warehouses.
- Transfers.
- Purchase orders.
- Suppliers.
- Advanced stock forecasting.
- Route optimization.

## Finance

- Accounts receivable.
- Customer credit.
- Statements.
- Reconciliation.
- Accounting integrations.
- Egyptian Tax Authority e-invoicing (الفاتورة الإلكترونية) for registered merchants.
- Tax engine if required.

## Technical Scale

Only when justified by real scale:

- Dedicated search engine.
- Event-driven services.
- Specialized Go services.
- Advanced caching.
- Read replicas.
- Service decomposition.

---

# Roadmap Principle

Do not build V2 or Later functionality into V1 merely because the architecture might eventually support it.

V1 should optimize for:

- Correct tenant isolation.
- Correct wholesale pricing.
- Correct inventory behavior.
- Correct order lifecycle.
- Simple merchant operations.
- Fast mobile ordering.
