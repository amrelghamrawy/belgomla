# BelGomla E-commerce — Business Rules

## A. Tenancy

### BR-T01 — Tenant = Independent Store

Each tenant represents one independent merchant store.

### BR-T02 — Tenant Resolution

The tenant is identified from the current domain/subdomain.

Example:

`alnoor.gomalecommerce.app` → Tenant: مكتبة النور

### BR-T03 — Strict Tenant Isolation

Tenant-owned data must never leak between tenants.

This includes:

- Products
- Categories
- SKUs
- Pricing
- Inventory
- Customers
- Orders
- Carts
- Addresses
- Tenant users
- Settings
- Notifications
- Audit records
- Collection records

### BR-T04 — Cross-Tenant IDs Are Invalid

If a request attempts to access a product/order/customer belonging to another tenant, it must not expose the resource.

Preferred behavior: return `404`.

### BR-T05 — Storefront Is Tenant-Scoped

A storefront only displays products belonging to its current tenant.

There is no cross-tenant product listing in V1.

### BR-T06 — Tenant Users (Merchant Side)

A tenant has one or more tenant users.

V1 has a single role: `owner`. All tenant users have full dashboard access.

Roles and granular permissions are V2.

### BR-T07 — Tenant Lifecycle

Tenants are created, activated and suspended by an internal CLI command (no Platform Admin UI in V1).

A `Suspended` tenant:

- Storefront returns a "store unavailable" page.
- Dashboard login is refused.
- Data is retained.

---

## B. Product and SKU

### BR-P01 — Product Belongs to One Tenant

Every product belongs to exactly one tenant.

### BR-P02 — Product and SKU Are Separate Entities

`Product` and `SKU` are separate entities. A product has one or more SKUs (1:N).

In V1:

- The dashboard creates exactly one SKU per product automatically.
- Variants (color/size) are not exposed in the UI.
- Pricing, MOQ, inventory and order items are attached to the SKU, never to the product.

This keeps V2 variants possible without a data migration.

### BR-P03 — SKU Is Tenant-Scoped

SKU codes must be unique within a tenant.

The same SKU code may exist in another tenant.

Example:

- Tenant A: `PEN-001`
- Tenant B: `PEN-001`

Both are valid.

### BR-P04 — Product Status

V1 supports:

- Active
- Coming Soon (visible on the storefront with "قريبًا", not orderable, no stock check)
- Inactive

Coming Soon exists because publishers announce new-year editions and final revisions before they ship, and retailers plan purchases around them. Pre-ordering a Coming Soon product is V2 (see `06-open-decisions.md` D21).

Inactive products:

- Are not visible to customers.
- Cannot be added to a new cart.
- Cannot be included in a new order.
- Remain available in historical orders.

If a product becomes Inactive while it is in a customer's cart, the cart line is removed at the next cart load / checkout and the customer is shown a message.

### BR-P05 — Historical Order Preservation

Products and SKUs used by historical orders must never be hard-deleted.

Archiving/inactivation is the only removal mechanism.

### BR-P06 — Categories

Categories belong to the tenant.

A category containing products should not be hard-deleted.

Use archive/inactive behavior instead.

Categories are a tree of unlimited depth. Any category can have sub-categories (`parent_id`, nullable). A category cannot be its own ancestor.

A product belongs to exactly one category (any level). Listing a category shows products of that category and all its descendants.

### BR-P08 — Custom Product Fields

A tenant can define its own custom product fields (e.g. Brand, Material, Country of origin) without a code change.

A custom field definition has:

- Label (Arabic).
- Key (machine name, unique per tenant).
- Type: `text`, `number`, `select` (single value from tenant-defined options), `boolean`.
- Required (yes/no).
- Show on storefront (yes/no).
- Filterable on storefront (allowed only for `select` and `boolean`).
- Sort order.

Rules:

- Definitions are tenant-scoped and apply to all products of the tenant.
- Values are stored per product as `{ key: value }` (JSONB), validated against the definition on save.
- Deleting a definition is soft (archive); existing values are kept but hidden.
- Changing a field's type after values exist is not allowed in V1.
- `select` options have a label and a sort order; options can be archived but not deleted once used.
- Custom fields are not part of the order item snapshot.

Storefront behavior:

- Filterable fields are shown as facets in the order defined by the merchant (`filter_order`). For school books this is Grade → Subject → Publisher.
- Facets combine with AND; each facet shows only options that still have active products given the other selected facets.
- Facet selections are reflected in the URL so a merchant can share a link such as "كل كتب أولى إعدادي الأضواء".
- Basic search matches product name, SKU code, and the option labels of `select` custom fields (so "علوم تانية اعدادي الأضواء" finds the book). Every word must match; both sides are folded the same way (D32: tashkeel removed, alef forms unified, ى→ي, ة→ه, colloquial ث→ت, ذ→د, ظ→ض).

### BR-P09 — Vertical Templates

The platform ships predefined custom-field templates per vertical. A template is applied once at tenant creation (`tenant:create --vertical=school-books`) and simply inserts field definitions and options; after that the merchant owns and edits them freely.

`school-books` template:

| Key             | Label (ar)     | Type   | Filterable | Options (initial)                                                                   |
| --------------- | -------------- | ------ | ---------- | ----------------------------------------------------------------------------------- |
| `grade`         | السنة الدراسية | select | yes        | KG1, KG2, أولى ابتدائي … ثالثة ثانوي                                                |
| `subject`       | المادة         | select | yes        | عربي، إنجليزي، رياضيات، علوم، دراسات، دين، فرنساوي، فيزياء، كيمياء، أحياء، فلسفة، … |
| `publisher`     | الشركة         | select | yes        | الأضواء، المعلم، كراسة الأول، سلاح التلميذ، الامتحان، بت بايت، جيم، …               |
| `term`          | الترم          | select | yes        | الترم الأول، الترم الثاني، كامل العام                                               |
| `track`         | المسار         | select | yes        | عربي، لغات                                                                          |
| `edition_type`  | نوع النسخة     | select | yes        | الكتاب الأساسي، المراجعة النهائية، كتاب الامتحانات، ملحق المراجعة والامتحانات       |
| `academic_year` | العام الدراسي  | select | yes        | 2025/2026، 2026/2027                                                                |

Other initial templates: `stationery` (brand, color, size), `hardware` (brand, material, size). A tenant can also start with no template.

### BR-P10 — Product Relations

A product can be linked to other products of the same tenant with a typed relation:

- `revision_of`: this product is the final revision / exam edition of the linked main book.
- `companion`: sold together (e.g. الترم الأول ↔ الترم الثاني of the same book).

Rules:

- Relations are tenant-scoped and one-directional with an automatic reverse display (the main book shows its revisions; the revision shows its main book).
- The storefront product page shows related products as "نسخ أخرى من هذا الكتاب".
- When a new academic year starts, the merchant inactivates old-year products; relations to inactive products are hidden, not deleted.

### BR-P11 — Duplicate Product

The dashboard offers "duplicate product": copies name, category, custom fields, units, base price, tiers and MOQ into a new draft product with a new SKU code and zero stock. This is how a merchant creates the same book for another grade or creates the final-revision edition quickly.

### BR-P12 — Season Rollover (School Books)

School-book merchants replace most of the catalog every academic year. V1 supports this with:

- Bulk status change by filter (e.g. inactivate all products where `academic_year = 2025/2026`).
- Bulk pricing by filter (BR-PR11).
- Duplicate product (BR-P11).
- Full bulk import/export remains V2.

### BR-P13 — Printable Price List

Retailers today receive wholesale price lists as images or PDFs in WhatsApp/Telegram groups. The storefront provides a print-friendly price list page for any facet selection (e.g. all الأضواء books for أولى إعدادي) showing name, cover price, wholesale price, MOQ and availability. The merchant can share its URL; the browser's print-to-PDF is used in V1. Excel/PDF export is V2.

### BR-P07 — Product Images

A product can have:

- One main image.
- Additional gallery images.

---

## C. Selling Units

### BR-U01 — Tenant-Defined Units

Each tenant defines its own unit names such as:

- Piece
- Box
- Carton
- Dozen
- Pack
- Bundle

Unit names are not hardcoded to a single vertical.

### BR-U02 — One Selling Unit Per SKU

In V1 every SKU is sold in exactly **one** selling unit.

A SKU defines:

- `selling_unit` (e.g. Carton)
- `base_unit` (e.g. Piece)
- `conversion_factor` = how many base units in one selling unit (e.g. 240)

Selling the same SKU in several units (piece and carton) is V2. If a merchant needs that in V1, they create two SKUs.

### BR-U03 — Base Unit Is the Inventory Unit

Inventory (On Hand / Reserved / Available) is always stored in the base unit.

Ordered quantity in base units = `quantity × conversion_factor`.

### BR-U04 — Pricing and MOQ Are in the Selling Unit

Pricing tiers, base price and MOQ are expressed in the SKU's selling unit.

Example:

- SKU "Blue pen" sold per carton (240 pieces).
- MOQ = 5 cartons.
- Tier 10+ cartons = 90 EGP per carton.

### BR-U05 — Integer Quantities Only

V1 does not support fractional quantities.

Valid:

- 1 carton
- 5 cartons
- 100 cartons

Invalid:

- 1.5 cartons
- 0.5 carton

---

## D. Minimum Order Quantity

### BR-MOQ01 — SKU-Level MOQ

Minimum Order Quantity is defined per SKU, in its selling unit. Default = 1.

Example:

`MOQ = 5 cartons`

The customer cannot order fewer than 5 cartons of that SKU.

### BR-MOQ02 — MOQ Is Not Aggregated

MOQ is evaluated per SKU.

Example:

- SKU A MOQ = 10
- SKU B MOQ = 10

Ordering 5 of A + 5 of B does not satisfy either MOQ.

---

## E. Quantity-Based Wholesale Pricing

### BR-PR01 — Base Price Is Mandatory

Every SKU has a mandatory `base_price` per selling unit.

Pricing tiers are optional and only reduce the price for larger quantities.

### BR-PR02 — Independent Tiers Per SKU

Every SKU has its own quantity pricing tiers.

Quantities from different SKUs are never combined to reach a pricing tier.

### BR-PR03 — Tier Structure

A tier = `min_quantity` + `unit_price`. There is no explicit max; the next tier's `min_quantity` ends the previous one.

Example (base price = 100 EGP):

| Tier min qty | Unit Price |
| -----------: | ---------: |
|            1 |    100 EGP |
|           10 |     90 EGP |
|          100 |     80 EGP |

### BR-PR04 — Price Resolution

For a requested quantity:

1. Take all tiers whose `min_quantity ≤ quantity`.
2. If any exist, use the one with the highest `min_quantity`.
3. If none exist, use `base_price`.

Example: quantity 35 → 90 EGP. Quantity 7 with tiers starting at 10 → base price.

### BR-PR05 — No Duplicate Tier Thresholds

Two tiers of the same SKU cannot share the same `min_quantity`. Tier prices must be `> 0`.

### BR-PR06 — Checkout Recalculation

The cart is not the final pricing authority.

At checkout, inside one database transaction:

1. Tenant status is checked (Active).
2. Current product status is checked (Active).
3. Current base price and tiers are loaded.
4. Price is recalculated.
5. MOQ is validated.
6. Stock is validated (`Available ≥ quantity × conversion_factor`).
7. Minimum order value is validated.
8. Stock is reserved and the order is created.

Any failing step rejects the whole checkout with an Arabic error identifying the SKU.

### BR-PR07 — Price Snapshot

When an order is created, the order item stores the actual applied:

- Product name.
- SKU name and SKU code.
- Selling unit name and conversion factor.
- Quantity.
- Unit price.
- Line total.

Later catalog changes must not modify historical orders.

### BR-PR10 — Cover Price and Percentage Pricing

In the school-book trade every book has a printed **cover price** (سعر الغلاف), and wholesale prices are negotiated as a **discount percentage off the cover price** ("الأضواء بخصم 25%"). The model supports this without changing the pricing engine:

- A SKU has an optional `cover_price` (سعر الغلاف). When set, the storefront shows cover price, wholesale price and the implied discount.
- `base_price` and tier prices can be entered **either** as an absolute unit price **or** as a discount percentage off `cover_price`. The dashboard converts the percentage to an absolute `unit_price` at save time; the stored and snapshotted value is always the absolute price, plus the percentage used, for display.
- Changing `cover_price` later does not silently recompute prices; the dashboard offers "recompute from percentages" as an explicit action (audited).
- Pricing resolution (BR-PR04) is unchanged.

### BR-PR11 — Bulk Pricing by Filter

A merchant with hundreds of books sets prices per publisher or per grade, not per SKU. The dashboard supports a bulk pricing action:

- Select products by filter (e.g. `publisher = الأضواء`, `academic_year = 2026/2027`).
- Apply: base price = X% off cover price, and optional tiers (e.g. 10+ → Y%, 50+ → Z%).
- The action writes concrete prices/tiers to every matching SKU, in one transaction, with one audit record for the bulk action and one per SKU.

Products without `cover_price` are skipped and reported.

### BR-PR08 — No Manual Discount in V1

Manual order/item discounts are explicitly postponed to V2.

### BR-PR09 — Taxes

Prices entered by the merchant are final customer prices. V1 has no tax engine and does not add VAT on top.

---

## F. Minimum Order Value

### BR-MOV01 — Tenant-Level Setting

Minimum Order Value is a tenant-level setting.

Each tenant can independently:

- Enable it.
- Disable it.
- Set the required amount.

### BR-MOV02 — Final Total

Minimum Order Value is evaluated against the final order items total after quantity-based pricing.

Since V1 has no delivery fees, discounts or taxes, items total = order total.

Example:

7 cartons × 100 = 700 EGP.

If minimum order value = 1000 EGP, checkout is rejected.

If another item increases the final total to 1050 EGP, checkout is allowed.

### BR-MOV03 — No Global Platform Default Required

The setting belongs to the tenant, not the platform.

---

## G. Inventory

### BR-INV01 — No Backorders in V1

Customers cannot order quantities greater than available stock.

### BR-INV02 — Inventory State

Each SKU has, in base units:

- On Hand
- Reserved
- Available

Formula:

`Available = On Hand - Reserved`

### BR-INV03 — Reservation at Order Creation

Stock is reserved when the order is created (status `Pending`), inside the checkout transaction.

`Reserved += quantity × conversion_factor`

Reservation and the stock check must be atomic (row lock) so concurrent checkouts cannot oversell.

### BR-INV04 — Cancellation Release

When an order is cancelled (from any status before `Delivered`):

`Reserved -= reserved quantity`

### BR-INV05 — Delivery Deduction

When the order becomes `Delivered`:

`On Hand -= reserved quantity`

`Reserved -= reserved quantity`

### BR-INV06 — Inventory Movements

Every change to On Hand is recorded as an inventory movement with type:

- `opening` (initial stock)
- `adjustment` (manual merchant change, with reason)
- `sale` (delivery deduction, linked to the order)

Reservation changes are not movements; they are derived from order status.

**Returns (المرتجع) are not in V1. They are planned for V2** (decided 2026-10-09). Because the school-book trade relies on end-of-season returns, the domain model reserves the `return` movement type and an `order_returns` entity now, so V2 returns ship without migrating existing data.

### BR-INV08 — Adjustments Cannot Go Below Reserved

A stock adjustment can never set `On Hand` below `Reserved`, because reserved units belong to open orders.

Dashboard behavior:

- The adjustment form shows On Hand, Reserved and Available side by side, and states the minimum allowed value: "أقل كمية ممكنة: {reserved} (محجوزة لطلبات قائمة)".
- If the merchant enters a lower value, the request is rejected with an Arabic message listing the open orders holding the reservation, each linked to the order page: "لا يمكن تقليل المخزون إلى {x} لأن {reserved} وحدة محجوزة للطلبات: #1024، #1031. ألغِ الطلبات أو سلّمها أولًا."
- The same rule applies to bulk adjustments; the response reports the SKUs that were skipped and why.

### BR-INV07 — Unconfirmed Orders Hold Stock

A `Pending` order holds its reservation until the merchant confirms or cancels it. There is no automatic expiry in V1. The dashboard should highlight Pending orders older than 24 hours.

---

## H. Orders

### BR-O01 — Order Status Flow

V1 order lifecycle:

`Pending → Confirmed → Preparing → Out for Delivery → Delivered`

Only forward transitions along this path are allowed. Skipping a step is allowed (e.g. `Confirmed → Out for Delivery`). Moving backwards is not.

`Cancelled` can be reached from any status except `Delivered`.

`Delivered` and `Cancelled` are terminal.

### BR-O02 — Who Can Cancel

- Customer: only while the order is `Pending`.
- Merchant: from any status except `Delivered`.

Cancellation requires a reason (free text) and releases the reservation (BR-INV04).

### BR-O03 — Cancellation and Collection

An order with `collected_amount > 0` cannot be cancelled until the collection records are voided. (Pending confirmation, see `06-open-decisions.md`.)

### BR-O04 — Order History

Every status change is recorded in order status history with actor, timestamp and optional note.

### BR-O05 — Historical Pricing

Order totals and item prices are snapshots and must remain stable even if catalog pricing changes later.

### BR-O06 — Orders Are Immutable After Creation

Order items (SKUs, quantities, prices) cannot be edited after creation in V1. The merchant cancels and the customer re-orders. Partial fulfilment is V2.

### BR-O07 — Merchant Delivery

The merchant is responsible for delivery in V1.

### BR-O08 — Order Total

`order_total = Σ line_total`. No fees, discounts or taxes in V1.

### BR-O09 — Order Number

Each tenant has its own sequential order number starting at 1 (e.g. `#1024`). Order numbers are unique per tenant, not globally.

### BR-O10 — Order Delivery Snapshot

The order stores a snapshot of the chosen delivery address and the customer phone at creation time.

The customer cannot change it afterwards. The merchant may edit the address snapshot while the order is before `Out for Delivery` (audited). Pending confirmation, see `06-open-decisions.md` (D9).

---

## I. Collection / Payment

### BR-C01 — No Online Payment

V1 has no payment gateway.

### BR-C02 — Collection Records

The merchant records each cash collection as a collection record:

- Order
- Amount (> 0)
- Collected at (date/time)
- Recorded by (tenant user)
- Note (optional)

A collection record can be voided (not deleted) with a reason.

Method in V1 is always `cash`.

### BR-C03 — Collection Status Is Derived

`collected_amount = Σ non-voided collection amounts`

| Condition                          | Status              |
| ---------------------------------- | ------------------- |
| collected_amount = 0               | Unpaid              |
| 0 < collected_amount < order_total | Partially Collected |
| collected_amount ≥ order_total     | Collected           |

Collecting more than the order total is rejected.

### BR-C04 — Collection Is Independent of Order Status

Order status and collection status are separate.

Example:

`Order = Delivered`, `Collection = Unpaid` is valid.

Collections can be recorded at any order status except `Cancelled`.

The "mark Delivered" action in the dashboard offers a shortcut "and collected full amount" to create both changes in one step.

---

## J. Customers

### BR-CU01 — Customer Is Tenant-Scoped

A customer belongs to one tenant.

The same person buying from two merchants has two unrelated customer records.

### BR-CU02 — No Cross-Tenant Customer Visibility

Customers and their orders are visible only inside their tenant.

### BR-CU03 — Multiple Addresses

A customer may have multiple delivery addresses and chooses one at checkout.

### BR-CU04 — Customer Authentication

Customers authenticate with **phone number + password**, scoped to the tenant (phone is unique per tenant, not globally).

Customers self-register on the storefront. The phone number must be verified by OTP (BR-CU07) before the account becomes `Active`.

Password reset also uses OTP on the same phone.

Prices are public by default. Tenant setting `require_login_for_prices` hides prices (and add-to-cart) from anonymous visitors.

New customers can order immediately after verification. Tenant setting `require_customer_approval` (default off) puts verified customers in `Pending Approval` until the merchant approves them.

### BR-CU07 — OTP Verification Channel

OTP delivery order:

1. Send the OTP via **WhatsApp**.
2. If the WhatsApp provider reports the number is not reachable on WhatsApp (or the send fails), send the OTP via **SMS** automatically.
3. The customer can also tap "send by SMS instead" after 60 seconds.

OTP rules:

- 6 digits, valid for 10 minutes, single use.
- Maximum 5 OTP sends per phone per hour, maximum 5 verification attempts per OTP.
- OTP sends are logged (channel, provider message id, status) per tenant.
- OTP is the only WhatsApp/SMS use in V1; order notifications remain V2.

### BR-CU08 — Merchant-Created Customers and Invitations

The merchant can create a customer from the dashboard with name, phone and optional addresses.

Then the merchant can **invite** the customer:

- An invitation message with a one-time link is sent via the same channel order as BR-CU07 (WhatsApp, then SMS).
- The link opens the storefront, lets the customer set a password, and marks the phone as verified (the link itself proves phone ownership).
- Invitation link is valid for 7 days and can be re-sent.
- Customer status while invited and not yet activated: `Invited`.
- A merchant-created customer who never activates can still be used by the merchant for reference, but cannot log in or order.

A self-registering customer whose phone already exists as `Invited` in the same tenant is routed to the activation flow instead of creating a duplicate.

### BR-CU05 — Customer Status

Customer statuses:

- `Pending Verification` (self-registered, OTP not yet confirmed).
- `Invited` (merchant-created, link not yet used).
- `Pending Approval` (verified, waiting for merchant approval; only when `require_customer_approval` is on).
- `Active`.
- `Blocked` (cannot log in or order).

Only `Active` customers can log in and order.

### BR-CU06 — Cart

Each customer has at most one open cart per tenant, stored server-side. Guest (anonymous) carts are not supported in V1; the customer must log in to add to cart.

---

## K. Notifications

### BR-N01 — Channels

- Email is out of scope for V1.
- WhatsApp and SMS exist in V1 **only** for OTP and customer invitations (BR-CU07, BR-CU08). Order status notifications over these channels are V2.

### BR-N02 — In-App Notifications

V1 provides in-app (dashboard) notifications to the merchant for:

- New order.
- Customer cancellation.

Customer-side notifications are an open decision, see `06-open-decisions.md`.

---

## L. Audit

Important merchant actions must be auditable.

At minimum:

- Base price and tier changes.
- MOQ changes.
- Stock adjustments.
- Order status changes.
- Collection records (create / void).
- Product and category archive/inactivation.
- Custom field definition changes.
- Bulk status changes (one audit record per affected product plus one for the bulk action).
- Customer create / invite / block.
- Tenant settings changes.

An audit record captures the actor (tenant user, customer, or system), action, entity, old/new values where applicable, timestamp, and request metadata such as IP.

Audit records are append-only.

---

## M. Tenant Settings

Tenant settings include, at minimum:

- Store name.
- Logo.
- Favicon.
- Branding colors.
- Phone.
- WhatsApp.
- Address.
- Description.
- Working hours.
- Currency = EGP (fixed in V1).
- Minimum Order Value enabled/disabled.
- Minimum Order Value amount.
- `require_login_for_prices` (default off).
- `require_customer_approval` (default off).

---

## N. Subscription

Subscription billing is not implemented in V1.

The tenant has an operational status:

- Active
- Suspended

Actual subscription plans, invoices, renewals, and payment collection are future scope.
