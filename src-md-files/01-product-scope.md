# BelGomla E-commerce — Product Scope

## 1. Product Vision

BelGomla E-commerce is a **multi-tenant B2B wholesale e-commerce SaaS**.

Each merchant operates an independent online wholesale store under its own subdomain.

Example:

- `alnoor.gomalecommerce.app` → مكتبة النور
- `anotherstore.gomalecommerce.app` → another merchant

The customer enters one specific merchant store and can see **only that merchant's products**.

BelGomla is **not a marketplace in V1**.

## 2. Initial Target Verticals

The first target businesses are wholesale merchants such as:

- Libraries
- School books
- Stationery
- Hardware / general small goods (الخردوات)

The architecture should remain generic enough to support other wholesale merchants later.

### 2.1 School Books Vertical (reference case)

School books are the most demanding V1 vertical and are used as the reference case for the catalog design. A school-book product is identified by:

- Grade (السنة الدراسية): e.g. أولى إعدادي.
- Subject (المادة): e.g. علوم.
- Publisher (الشركة / السلسلة): e.g. الأضواء، المعلم، كراسة الأول.
- Term (الترم): الترم الأول / الترم الثاني / كامل.
- Track (المسار): عربي / لغات.
- Edition type (نوع النسخة): الكتاب الأساسي / المراجعة النهائية / كتاب الامتحانات.
- Academic year (العام الدراسي): e.g. 2026/2027.

Publishers release a **final revision** edition (المراجعة النهائية) near the end of the school year. It is a separate product with its own SKU, price, stock and MOQ, linked to the main book so the storefront can show it next to the original.

None of these fields are hardcoded in the schema. They are delivered as a **vertical template** of custom fields (see BR-P09) applied when a tenant is created, and the merchant can edit them.

## 3. Core Business Model

Each tenant:

- Has its own storefront.
- Has its own dashboard.
- Owns its own products.
- Owns its own categories.
- Owns its own inventory.
- Owns its own customers.
- Owns its own orders.
- Controls its own wholesale pricing tiers.
- Controls its own minimum order value setting.
- Handles delivery itself.
- Collects money itself.

## 4. V1 Product Philosophy

V1 should be a focused wholesale ordering platform, not a full ERP.

The first release should solve:

1. Product catalog management, including tenant-defined custom product fields.
2. Wholesale quantity-based pricing.
3. Minimum order quantity.
4. Tenant-level minimum order value.
5. Inventory availability.
6. Customer ordering, with phone verification by OTP (WhatsApp, SMS fallback).
7. Merchant-created customers with invitation.
8. Merchant order management.
9. Merchant delivery.
10. Offline cash collection tracking.
11. Tenant-specific storefronts.
12. Strict tenant isolation.

## 5. Explicitly Out of Scope for V1

The following are not part of V1:

- Platform Admin UI (tenants are created and suspended by an internal CLI command, see Technical Constraints §18).
- Marketplace / cross-store browsing.
- Cross-tenant product discovery.
- Online payment gateways.
- Shipping-company integrations.
- Delivery fees (order total = items total in V1).
- Email notifications.
- SMS / WhatsApp **notifications** (order updates, marketing). WhatsApp/SMS are used in V1 **only** for OTP and customer invitations.
- Subscription billing implementation.
- Manual order discounts.
- Customer-specific negotiated pricing.
- Product variants (color/size). The schema supports them; the V1 UI does not expose them.
- Multiple selling units for the same SKU.
- Staff roles and permissions (V1 has a single "owner" role).
- Custom domains.
- Backorders.
- Returns / refunds flow (المرتجع). Planned for V2 as the first V2 feature; schema is prepared in V1.
- Editing an order's items after it is created (no partial fulfilment in V1).
- Automatic expiry of unconfirmed orders.
- Advanced accounting / accounts receivable.
- Reviews and ratings.
- Advanced search engines.

## 6. Language and UX

V1 is:

- Arabic only.
- RTL-first.
- Mobile-first.
- Designed primarily for mobile customers and merchants.

Currency:

- EGP.

Timezone:

- Africa/Cairo.

## 7. Tenant Storefront

A storefront should feel like the merchant's own website.

Customers should not be exposed to:

- Other tenants.
- Other merchants.
- Marketplace-style merchant listings.
- Cross-store product comparisons.

Tenant branding should include at least:

- Store name.
- Logo.
- Favicon.
- Primary/secondary branding colors.
- Phone.
- WhatsApp.
- Address.
- Description.
- Working hours.

## 8. Delivery Model

V1 uses merchant-managed delivery.

The merchant:

1. Receives the order.
2. Prepares it.
3. Delivers it.
4. Collects the money.

There is no shipping provider integration in V1.

## 9. Payment Model

V1 has no online payment.

The initial collection method is offline/cash collection.

Collection status should be independent from order delivery status.

Example:

- Order = Delivered
- Collection = Unpaid

This is valid.

## 10. Future Direction

The platform may later add:

- Online payments.
- Shipping integrations.
- Customer-specific pricing.
- Manual discounts.
- Subscription billing.
- WhatsApp/SMS order notifications (the OTP channel already exists in V1).
- Advanced staff management.
- Custom domains.
- Marketplace capabilities if the business direction ever changes.
