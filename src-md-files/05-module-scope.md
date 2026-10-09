# BelGomla E-commerce — Module Scope

## V1 Modules

### 1. Tenancy

Responsibilities:

- Tenant/store identity and status (Active / Suspended).
- Subdomain resolution and tenant context middleware.
- Tenant settings, including Minimum Order Value settings.
- Tenant users (`owner` role) and dashboard authentication.
- Tenant isolation (global scopes, policies).
- Artisan commands for tenant provisioning.

### 2. Storefront

Responsibilities:

- Tenant-branded store.
- Categories.
- Products.
- Faceted filtering and search over custom fields.
- Product details with related products.
- Customer registration / login.
- Cart.
- Checkout (delegates validation to Pricing, Inventory, Orders).
- Customer orders and customer cancellation.
- Customer account and addresses.

### 3. Catalog

Responsibilities:

- Categories (tree, unlimited depth).
- Products.
- Custom product field definitions, options and values.
- Vertical templates (seeders).
- Product relations.
- Duplicate product, bulk status change.
- SKUs (1:N under product).
- Images.
- Product status.
- Tenant-defined units and SKU selling unit / base unit / conversion factor.

### 4. Pricing

Responsibilities:

- Base price per SKU.
- Quantity tiers per SKU.
- MOQ per SKU.
- Price resolution (BR-PR04).
- Checkout recalculation and MOQ validation.
- Minimum Order Value validation (reads the setting from Tenancy).
- Price snapshots for order items.

### 5. Inventory

Responsibilities:

- On Hand / Reserved / Available per SKU in base unit.
- Adjustments with reason.
- Movements (opening, adjustment, sale).
- Reservation / release / deduction with row locking.

### 6. Customers

Responsibilities:

- Customer records and status (Pending Verification / Invited / Active / Blocked).
- Customer authentication (phone + password) and OTP verification flow.
- Merchant-created customers and invitations.
- Customer addresses.
- Customer order history.

### 7. Orders

Responsibilities:

- Order creation (checkout transaction owner).
- Order items and snapshots.
- Per-tenant order numbers.
- Status lifecycle and allowed transitions.
- Status history.
- Cancellation rules (customer / merchant).
- Delivery address and phone snapshot.

### 8. Delivery

Responsibilities:

- Merchant delivery.
- Delivery status represented through the order lifecycle (`Preparing`, `Out for Delivery`, `Delivered`).

No carrier integration and no delivery fees in V1. This module is intentionally thin and exists so V2 delivery features have a home.

### 9. Collections

Responsibilities:

- Collection records (create / void).
- Derived collection status: Unpaid / Partially Collected / Collected.
- Outstanding balance per order and per customer.

### 10. Dashboard

Responsibilities:

- Product management.
- Custom product fields management.
- Category management (tree).
- Customer creation and invitation.
- Pricing management.
- Inventory management.
- Order management (status transitions, cancel, mark delivered + collected).
- Customer management (view, block).
- Collections management.
- Basic reports.
- Settings.

### 11. Notifications / Messaging

Responsibilities:

- In-app merchant notifications (new order, customer cancellation).
- Messaging service: WhatsApp driver, SMS driver, fallback, send log, delivery webhooks.
- Used by Customers module for OTP and invitations only.

### 12. Audit

Responsibilities:

- Append-only business-change audit trail.

---

# V2 Modules

Potential V2 modules:

- Product variants and multiple selling units.
- Advanced pricing and discounts.
- Customer pricing and customer groups.
- Order editing, partial fulfilment, returns.
- Online payments.
- Shipping integrations and delivery fees/zones.
- WhatsApp/SMS order notifications, Telegram.
- Staff roles and permissions.
- Subscription billing.
- Advanced reporting.
- Bulk import/export.

---

# Later Modules

Potential later modules:

- Platform Admin.
- Marketplace.
- Warehouses.
- Procurement.
- Suppliers.
- Accounting.
- Accounts receivable.
- Advanced promotions.
- Loyalty.
- Reviews.
- Advanced analytics.
- Search infrastructure.
- Recommendation systems.
