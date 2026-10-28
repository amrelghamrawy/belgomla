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

Each tenant user has one role (V2 Task 05, D82). Roles and their permissions are fixed by the platform:

| Permission | owner | manager | sales | warehouse | accountant |
| ---------- | :---: | :-----: | :---: | :-------: | :--------: |
| `catalog.manage` (products, categories, units, fields) | ✓ | ✓ | | | |
| `pricing.manage` (incl. promotions, V2 Task 12, BR-PR15) | ✓ | ✓ | | | |
| `pricing.customers` (customer groups, negotiated prices, the group on the customer form; V2 Task 11, BR-PR12, BR-PR13) | ✓ | ✓ | | | |
| `inventory.adjust` | ✓ | ✓ | | ✓ | |
| `orders.view` (also reading the catalog and stock) | ✓ | ✓ | ✓ | ✓ | ✓ |
| `orders.manage` (transition, cancel, address) | ✓ | ✓ | ✓ | ✓ (preparing / out for delivery only, no cancel) | |
| `orders.create` (place an order for a customer, V2 Task 07, BR-O11) | ✓ | ✓ | ✓ | | |
| `orders.override_mov` (waive the minimum order value of one such order, D84) | ✓ | ✓ | | | |
| `orders.edit` (change the lines of a pending or confirmed order, V2 Task 09, BR-O12) | ✓ | ✓ | ✓ | | |
| `orders.discount` (manual discounts, V2 Task 08, BR-PR08; sales: line discounts up to the store's cap only, D85) | ✓ | ✓ | ✓ | | |
| `orders.returns` | ✓ | ✓ | | ✓ | |
| `customers.manage` | ✓ | ✓ | ✓ | | |
| `customers.credit` (limits, terms) | ✓ | ✓ | | | ✓ |
| `collections.manage` | ✓ | ✓ | | | ✓ |
| `reports.view` | ✓ | ✓ | | | ✓ |
| `settings.manage` (incl. the WhatsApp link) | ✓ | | | | |
| `users.manage` | ✓ | | | | |
| `audit.view` | ✓ | ✓ | | | |

- Every dashboard endpoint needs a permission; without it the answer is `403 forbidden` and the screen is hidden. Customers can be listed by anyone with `customers.manage`, `customers.credit` or `collections.manage`; changing a customer's name or addresses needs `customers.manage`, changing the credit terms `customers.credit`. Delivering with "collect the full amount" needs `collections.manage`.
- A user sees only the notifications their role covers: order notifications need `orders.manage`, `whatsapp_dropped` needs `settings.manage`. Read state stays shared (D57).
- A store always keeps at least one active owner: the last active owner can be neither demoted nor disabled (`409 last_owner`). A role change is audited (`tenant_user.role_changed`).
- `tenant:create` and `tenant:demo` create an owner; a user added from the dashboard is an owner unless a role is picked.

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

Coming Soon exists because publishers announce new-year editions and final revisions before they ship, and retailers plan purchases around them.

V2 (Task 10, D21 resolved, D88): the merchant may open a Coming Soon product to **pre-orders** (`preorder_enabled`), with the day it is expected (`expected_at`, shown on the storefront) and an optional cap on the total pre-ordered quantity (`preorder_limit`, selling units). Customers then pre-order it ("احجز الآن", BR-O14); it is still not orderable as a normal order and no stock is checked or held. Going `active` does not convert anything by itself: the merchant sees the pre-order demand and converts the pre-orders when the stock is in.

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

Every root category belongs to a **section** (`kind`, D68): `books` (كتب خارجية), `stationery` (أدوات دراسية), `toys` (ألعاب) or `general`. The merchant sets it when creating or editing a root; sub-categories inherit it and cannot carry their own (`422`), and moving a subtree under another root re-kinds it. A product's kind is its category's kind; it decides what the SKU's reference price is called (BR-PR10) and which custom fields apply (BR-P08).

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

- Definitions are tenant-scoped. A definition is bound to one section (`kind`, D69) or to none (null = every section). It applies to a product when its kind is null or equals the kind of the product's category: only applicable fields are shown on the form and the storefront, required, or offered as facets inside a category (outside any category every filterable field is a facet). A value sent for a non-applicable field is rejected (`422 validation_failed`, "هذا الحقل لا ينطبق على قسم هذا المنتج") unless it is the stored value; stored values of non-applicable fields are kept and hidden, like archived ones, so a product moved to another section loses nothing.
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

Templates carry the section their fields belong to (D69): every school-books field is bound to `books`, every stationery field to `stationery`, hardware fields to no section. A store that sells several sections applies several templates (the demo seeder applies school-books and stationery and adds a toys-only `age` field).

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
- Catalog import / export (V2 Task 06): the catalog (or any filter of it) downloads as an Excel or CSV file in the import format, and a file in that format imports back: a new SKU code creates the product, a known one updates it, after a preview that lists every row error and writes nothing (D95). Categories, units and options must exist; images are not imported.

### BR-P13 — Printable Price List

Retailers today receive wholesale price lists as images or PDFs in WhatsApp/Telegram groups. The storefront provides a print-friendly price list page for any facet selection (e.g. all الأضواء books for أولى إعدادي) showing name, cover price, wholesale price, MOQ and availability. A logged-in customer sees their own wholesale price (BR-PR14, V2 Task 11). The page itself lets the visitor change the selection (search, category, facets), hidden when printed. The merchant can share its URL. The page also downloads the same selection as an Excel file (V2 Task 06); PDF is the browser's print-to-PDF of the page (D83). A guest of a store that hides prices gets no file.

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

### BR-U02 — Selling Units of a SKU

A SKU has one `base_unit` (e.g. Piece), the unit its stock is kept in (BR-U03), and is sold in **one or more selling units** (V2 Task 13, D91; V1 allowed exactly one). Each selling unit defines:

- its `unit` (e.g. Carton), at most once per SKU;
- `conversion_factor` = how many base units in one of it (e.g. 240; 1 for the base unit itself);
- its own prices, MOQ and tiers (BR-U04).

One active selling unit is the **default**: shown first, and used where a single unit stands for the SKU (the product card, the price list order, the pre-order cap). A notebook can be sold by the piece (factor 1, 12 EGP) and by the carton of 48 (factor 48, its own price and tiers); both draw on the same stock. A unit the merchant stops selling is archived, not deleted.

### BR-U03 — Base Unit Is the Inventory Unit

Inventory (On Hand / Reserved / Available) is always stored in the base unit.

Ordered quantity in base units = `quantity × conversion_factor`.

### BR-U04 — Pricing and MOQ Are in the Selling Unit

Pricing tiers, base price and MOQ are expressed in the selling unit they belong to: every selling unit of a SKU has its own (V2 Task 13, D91). A cart or order line is in one selling unit; two units of one SKU are two lines.

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

Minimum Order Quantity is defined per SKU selling unit, in that unit (V2 Task 13, D91: each unit has its own). Default = 1.

Example:

`MOQ = 5 cartons`

The customer cannot order fewer than 5 cartons of that SKU.

### BR-MOQ02 — MOQ Is Not Aggregated

MOQ is evaluated per SKU selling unit (a line), never summed across lines or units.

Example:

- SKU A MOQ = 10
- SKU B MOQ = 10

Ordering 5 of A + 5 of B does not satisfy either MOQ.

---

## E. Quantity-Based Wholesale Pricing

### BR-PR01 — Base Price Is Mandatory

Every selling unit of a SKU has a mandatory `base_price` (V2 Task 13, D91).

Pricing tiers are optional and only reduce the price for larger quantities.

### BR-PR02 — Independent Tiers Per SKU

Every SKU selling unit has its own quantity pricing tiers (V2 Task 13, D91).

Quantities from different SKUs, or from different units of one SKU, are never combined to reach a pricing tier.

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

Two tiers of the same SKU selling unit cannot share the same `min_quantity`. Tier prices must be `> 0`.

A tier may not cost more than its unit's base price (D28: no surcharges); a tier equal to the base price is allowed. Violations are rejected together with `422 invalid_tiers`, one Arabic message per problem in `details.tiers`.

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

- A SKU has an optional reference price, stored and sent as `cover_price`. For products of the `books` section it is the printed cover price (سعر الغلاف); for every other section it is the retail price (سعر القطاعي), also optional (D68). The name follows the product's category kind everywhere it is shown (storefront, dashboard form, price list, the `cover_price_required` message); the column, the API field and the mechanics below do not change. When set, the storefront shows the reference price, the wholesale price and the implied discount.
- `base_price` and tier prices can be entered **either** as an absolute unit price **or** as a discount percentage off `cover_price`. The dashboard converts the percentage to an absolute `unit_price` at save time; the stored and snapshotted value is always the absolute price, plus the percentage used, for display.
- Changing `cover_price` later does not silently recompute prices; the dashboard offers "recompute from percentages" as an explicit action (audited). It re-derives the base price and every tier that has a stored percentage from the current cover price; absolute prices stay.
- Pricing resolution (BR-PR04) is unchanged.
- **Rounding (D27):** price = `cover_price × (100 − percent) / 100`, computed exactly (bcmath, never floats) and rounded **half-up to the piaster** (2 decimals): 120.00 at 25% → 90.00; 10.10 at 25% = 7.575 → 7.58. The implied discount shown next to an absolute price uses the same rounding.
- When both a price and a percentage are sent for the same value, the percentage wins and the price is computed from it. A percentage on a SKU without a cover price is rejected with `422 cover_price_required`.

### BR-PR11 — Bulk Pricing by Filter

A merchant with hundreds of books sets prices per publisher or per grade, not per SKU. The dashboard supports a bulk pricing action:

- Select products by filter (e.g. `publisher = الأضواء`, `academic_year = 2026/2027`).
- Apply: base price = X% off cover price, and optional tiers (e.g. 10+ → Y%, 50+ → Z%).
- The action writes concrete prices/tiers to every matching SKU, in one transaction, under one audit batch id: one `pricing.bulk_applied` record for the bulk action, and per SKU one record per kind of change (`sku.price_changed`, `sku.tiers_changed`, `sku.moq_changed`), as for a single-SKU edit.
- The given tiers replace each SKU's tiers (an empty list removes them); the MOQ changes only when one is given.
- A preview runs the same selection and checks without writing and returns `{ affected, skipped_no_cover_price }`.
- An empty filter is refused (as for bulk status, BR-P12).

Products without `cover_price` are skipped and reported.

### BR-PR08 — Manual Discounts (V2 Task 08, D85)

V1 had none. From V2 the merchant may lower the price of an order that is still `pending` or `confirmed`:

- **Line discount**: per unit, an amount in EGP or a % off the line's list price (the price the order was placed at, `list_unit_price`). The line's `unit_price` becomes the discounted price, never below 0.01, and `line_total = unit_price × quantity` as before.
- **Order discount**: an amount or a % of the items total (after the line discounts), with a required reason; the order total never goes below 0.01.
- Percentages are rounded half-up to the piaster (D27). Removing a discount restores the list price.
- Who: `orders.discount` (owner, manager, sales). A sales user gives line discounts only, each at most the store's `max_discount_percent_sales` % (none by default = no discounts by sales).
- The new total can never be below what was already collected. The collection status is derived again.
- Every change is audited (`order.discount_changed`, old and new values) and noted in the order history («خصم»). The customer sees the discounted prices and the order discount (not the reason), and gets a WhatsApp message only if the store notifies `discount` (BR-N03).
- A line's `discount_percent` (shown next to a tier price) is unrelated: it is the display margin off the cover price (BR-PR10).
### BR-PR12 — Customer Groups (V2 Task 11, D89)

The merchant defines wholesale levels («موزع», «مكتبة كبيرة», «مكتبة صغيرة»), each with one discount percentage off the base price (0–90) and a place in the list. A customer belongs to at most one group, set from the customer form or assigned in bulk (ticked customers, or every customer the list's filter selects). A group customer pays the group price, `base_price × (100 − %) / 100`, rounded half-up to the piaster (D27), whenever it is lower than the normal price (BR-PR14). Archiving a group stops its discount at once; its customers keep it and get their prices back if it is restored. Names are unique in the store. Groups, assignment and changes are audited (`customer_group.created/updated/archived/assigned`, `customer.group_changed`). Who: `pricing.customers`.

### BR-PR13 — Negotiated Customer Prices (V2 Task 11, D89)

The merchant sets a unit price for one customer on one SKU (in its selling unit, > 0, with an optional note), from the customer's page («أسعار خاصة»). It applies at every quantity and wins over the base price, the tiers and the group price, even when it is higher (BR-PR14). One price per customer and SKU; setting it again replaces it, removing it brings the normal price back. Audited (`customer.price_set`, `customer.price_removed`). Who: `pricing.customers`.

### BR-PR14 — Price Precedence (V2 Task 11, D89)

For a customer, SKU and quantity: (1) the customer's negotiated price for the SKU, if any; (2) otherwise the lowest of the normal price (BR-PR04: base or tier), the group price (BR-PR12) and the price of a live promotion on the SKU (BR-PR15, V2 Task 12, D90). Guests and customers without a group or negotiated price pay the normal price. The PriceResolver stays the single authority: it receives the customer's pricing context (their active group's % and their negotiated prices, read once per request) and never queries. Every price the customer sees or pays follows this rule: the storefront product list and page, the price list and its Excel file, the cart, checkout (BR-PR06), the merchant's quote and order (BR-O11), order edits (BR-O12) and pre-orders and their conversion (BR-O14). The order line snapshots the price and where it came from (`price_source`: base, tier, group or customer, BR-PR07); the storefront marks a group or negotiated price «سعرك الخاص». Manual discounts (BR-PR08) apply on top.

### BR-PR15 — Promotions (V2 Task 12, D90)

The merchant runs time-boxed offers («خصم 15% على كتب الأضواء حتى آخر الشهر»): a name, one % off the base price (more than 0, below 90), a start and an end in Cairo time, and the products it covers, chosen by the same filter as bulk pricing (BR-PR11, not empty) or as an explicit list of SKUs. The covered SKUs are fixed when the promotion is saved. While a promotion is live (`starts_at ≤ now < ends_at`, not a draft, not cancelled) every customer and guest pays the promotion price, `base_price × (100 − %) / 100` rounded half-up to the piaster, whenever it is the lowest price they can get (BR-PR14): a negotiated price still wins outright; otherwise the lowest of the normal price, the group price and the promotion price applies. Promotions never stack: a SKU in two live promotions gets the higher %. The storefront shows the offer's % and when it ends on the card, product page, cart and price list. Checkout prices at the moment of the order (BR-PR06), so an offer that ended in the meantime is not applied; order lines keep `price_source = promotion` and the promotion's id (BR-PR07). Only a draft or a promotion that has not started can be edited; any promotion that has not ended can be cancelled. Audited (`promotion.created`, `promotion.updated`, `promotion.cancelled`). Who: `pricing.manage`.

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

V2 (Task 09, BR-O13): when a line is delivered short, only the delivered units leave On Hand (`sale` movement of `delivered × conversion_factor`) and the rest of its reservation is released. A line the merchant removed (BR-O12) already gave its reservation back and is neither deducted nor released.

### BR-INV06 — Inventory Movements

Every change to On Hand is recorded as an inventory movement with type:

- `opening` (initial stock)
- `adjustment` (manual merchant change, with reason)
- `sale` (delivery deduction, linked to the order)
- `return` (V2, BR-R03/R06: goods a customer brought back, positive and linked to the order; negative when the return is voided)

Reservation changes are not movements; they are derived from order status.

Returns (المرتجع) were left out of V1 and shipped as the first V2 increment (D20, §H2).

### BR-INV08 — Adjustments Cannot Go Below Reserved

A stock adjustment can never set `On Hand` below `Reserved`, because reserved units belong to open orders.

Dashboard behavior:

- The adjustment form shows On Hand, Reserved and Available side by side, and states the minimum allowed value: "أقل كمية ممكنة: {reserved} (محجوزة لطلبات قائمة)".
- If the merchant enters a lower value, the request is rejected with an Arabic message listing the open orders holding the reservation, each linked to the order page: "لا يمكن تقليل المخزون إلى {x} لأن {reserved} وحدة محجوزة للطلبات: #1024، #1031. ألغِ الطلبات أو سلّمها أولًا."
- The same rule applies to bulk adjustments; the response reports the SKUs that were skipped and why (`below_reserved` with `reserved`/`min_allowed`, `not_found`, `unchanged`), and the valid ones are applied.

### BR-INV07 — Unconfirmed Orders Hold Stock

A `Pending` order holds its reservation until the merchant confirms or cancels it. The dashboard highlights Pending orders older than `stale_pending_hours` (default 24, D10).

Optional automatic expiry (V2 Task 04, D81): a store may set `pending_expiry_hours` (1–720, off by default, never below `stale_pending_hours`). A Pending order still unconfirmed that many hours after it was placed is cancelled by the system (`cancelled_by = system`, reason "انتهت مهلة تأكيد الطلب"), which releases its stock (BR-INV04). The merchant gets an `order_expired` notification and the customer the cancelled message when the store sends it (BR-N03). An order with money collected is never expired (BR-O03); it is left pending and logged. The check runs every 15 minutes, so an order may expire up to 15 minutes late. The orders list shows when each pending order will expire. A pre-order (BR-O14) holds no stock and waits for its product by design: it is never stale and never expires.

---

## H. Orders

### BR-O01 — Order Status Flow

V1 order lifecycle:

`Pending → Confirmed → Preparing → Out for Delivery → Delivered`

Only forward transitions along this path are allowed. Skipping a step is allowed (e.g. `Confirmed → Out for Delivery`). Moving backwards is not.

`Cancelled` can be reached from any status except `Delivered`.

`Delivered` and `Cancelled` are terminal.

V2 (Task 10, BR-O14): a pre-order (`kind = preorder`) stays `Pending` until it is cancelled; it never moves forward. It becomes normal orders by conversion.

### BR-O02 — Who Can Cancel

- Customer: only while the order is `Pending`.
- Merchant: from any status except `Delivered`.

Orders are placed by the customer at checkout, or by the merchant on the customer's behalf (V2, BR-O11).

Cancellation requires a reason (free text) and releases the reservation (BR-INV04).

### BR-O03 — Cancellation and Collection

An order with `collected_amount > 0` cannot be cancelled until the collection records are voided. (Pending confirmation, see `06-open-decisions.md`.)

### BR-O04 — Order History

Every status change is recorded in order status history with actor, timestamp and optional note.

### BR-O05 — Historical Pricing

Order totals and item prices are snapshots and must remain stable even if catalog pricing changes later.

### BR-O06 — When Order Lines Change

V1 orders were immutable. From V2 (Task 09) the lines of an order change in exactly two ways:

- While it is `pending` or `confirmed`, the merchant edits them (add a SKU, remove a line, change a quantity, BR-O12) instead of cancelling and asking the customer to re-order.
- On delivery, the merchant records what each line actually delivered when it is less than ordered (partial delivery, BR-O13).

From `preparing` on, nothing but a short delivery changes the lines. The customer never edits an order. Goods brought back after delivery are returns (§H2), which leave the lines as they were.

### BR-O07 — Merchant Delivery

The merchant is responsible for delivery in V1.

### BR-O08 — Order Total

`order_total = Σ line_total`. No fees, discounts or taxes in V1.

V2 (Task 08): `items_total = Σ line_total` (line totals after their line discounts) and `order_total = items_total − discount_amount`, the order discount (BR-PR08). A CHECK holds it.

V2 (Task 01): `net_total = order_total − returned_total`, where `returned_total` is the sum of the order's live returns (BR-R04). Everything the customer owes is computed on the net total.

### BR-O09 — Order Number

Each tenant has its own sequential order number starting at 1 (e.g. `#1024`). Order numbers are unique per tenant, not globally.

### BR-O10 — Order Delivery Snapshot

The order stores a snapshot of the chosen delivery address and the customer phone at creation time.

The customer cannot change it afterwards. The merchant may edit the address snapshot while the order is before `Out for Delivery` (audited). Pending confirmation, see `06-open-decisions.md` (D9).

### BR-O11 — Merchant-Placed Orders (V2 Task 07, D84)

Many wholesale orders arrive by phone or WhatsApp. A dashboard user with `orders.create` (owner, manager, sales) places the order for an existing **active** customer and one of their addresses. It goes through exactly the checkout checks of BR-PR06 (current prices and tiers, product active, MOQ, stock, minimum order value, credit limit BR-C05), reserves stock and takes the next order number of the store (BR-O09), and from then on lives like any other order. Differences:

- The actor of the history entry and of the `order.created` audit is the dashboard user; the order records `placed_by` (customer | tenant_user) and the dashboard shows «أنشأه التاجر».
- No `new_order` notification (the merchant made it).
- The owner or a manager (`orders.override_mov`) may waive the minimum order value for one order; it is audited. MOQ, stock and the credit limit are never waived.
- The customer gets a WhatsApp «تم تسجيل طلبك» message only when the merchant asks for it (and they did not opt out, BR-N03).
- Before placing, the form asks for a **quote**: the same checks as a dry run, nothing saved.

A blocked customer cannot be ordered for (`409 customer_blocked`), nor one who has not activated their account (`409 customer_not_active`).

### BR-O12 — Editing an Order's Lines (V2 Task 09, D87)

A dashboard user with `orders.edit` (owner, manager, sales) changes the lines of a `pending` or `confirmed` order (`409 order_locked` from `preparing` on). The request is the full list of lines the order should have:

- A line kept with another quantity is re-priced at the current tiers (BR-PR04) and keeps its manual discount (BR-PR08) on the new list price. Its reservation follows: the difference is reserved or released.
- A new SKU is checked and snapshotted like checkout (BR-PR06, BR-PR07) and reserved.
- A line left out is removed: its reservation is released, and the line is kept for history (`removed_at`), out of every total, stock move and return.
- An unchanged line keeps its snapshot price even when the catalog price changed (BR-O05). A line's SKU never changes (remove it and add the other).
- The added and changed lines go through the checkout line checks (product active, MOQ, stock); what the order already holds of a SKU counts as available to it. Any failure refuses the whole edit like checkout (`422 checkout_failed`) and nothing changes.
- Totals are recomputed (BR-O08): a % order discount is taken off the new items total; an EGP one that no longer fits refuses the edit (`422 discount_too_large`). The total can never be below what was collected (`409 below_collected`); the collection status is derived again.
- A higher total must fit the customer's credit limit (BR-C05), counting the order's old total once. The minimum order value (BR-MOV02) is not enforced: going below it is allowed and the merchant is warned.
- Every change is recorded as a line change (`added`, `removed`, `quantity`, with the quantities, the prices before and after and the user), the history gets the note «تعديل الطلب» (plus the merchant's note), and `order.lines_changed` is audited. Sending the lines the order already has changes nothing.
- The customer gets the `edited` WhatsApp text with the new total (on by default, BR-N03) and sees the changed and removed lines on the order page.

### BR-O13 — Partial Delivery (V2 Task 09, D86)

When moving an order to `delivered`, the merchant may record what each line actually delivered (0 up to the ordered quantity, selling units); a line not mentioned went out in full. Delivering nothing at all is refused: the order is cancelled instead.

- Only the delivered units are deducted (BR-INV05); the remainder is released to stock. There is no back-order or second shipment; the merchant places a new order for the rest (BR-O11).
- Each line keeps its `delivered_quantity` and is charged for it (`line_total = unit_price × delivered_quantity`); the order totals are recomputed (BR-O08). A % order discount follows the new items total; an EGP one is capped so the order still costs 0.01.
- What was already collected must still fit (`409 below_collected`); "collect the full amount" collects the new total.
- Each short line is recorded as a `delivered_short` line change; the history note defaults to «تسليم جزئي»; the audit lists the short lines.
- Returns count the delivered quantity (BR-R02).

### BR-O14 — Pre-orders (V2 Task 10, D88)

A customer secures a quantity of a Coming Soon product that takes pre-orders (BR-P04) before it ships.

- **Creation.** Pre-order lines sit in the cart apart from the order lines (BR-CU06) and are confirmed on their own (`POST /store/checkout/preorder`). The result is an order of `kind = preorder` with the next order number (BR-O09): `pending`, priced at today's tiers (indicative), its lines snapshotted like checkout (BR-PR07). The checks: the product is still Coming Soon with pre-orders on, the MOQ (BR-MOQ01), the merchant's cap over every open pre-order (under the product row lock), and the credit limit (BR-C05). No stock check and no minimum order value. The merchant gets a `new_preorder` notification.
- **No reservation.** A pre-order holds no stock (BR-INV01 stays true): its lines are not reserved, a cancel releases nothing, and it never appears among the orders holding a SKU (BR-INV08). It is never stale and never expires (BR-INV07).
- **What it cannot do.** It is never confirmed or moved on (`409 invalid_transition`); its lines and prices are not edited and take no discount (`409 order_locked`); it takes no collection or credit (`409 is_preorder`). Its delivery address can still be edited by the merchant (BR-O10).
- **Conversion, FIFO.** Once the product is `active` the merchant converts its open pre-orders (`orders.manage`), oldest `placed_at` first. Each pre-order, in its own transaction, gets what the stock allows of its lines of that product: a normal pending order of those quantities, placed by the merchant (`converted_from_id`), delivered to the pre-order's address snapshot with its note, re-priced at today's tiers for the quantity converted and reserved (BR-INV03). The MOQ, the minimum order value and the credit limit are not checked again. What is left stays on the pre-order (`converted_quantity`, recorded as a `converted` line change) until a later conversion or a cancel; a pre-order with nothing left is closed (`converted_at`). Pre-orders that get nothing (no stock left, or a customer who can no longer order) are untouched. The customer gets the `preorder_converted` WhatsApp text for each new order (BR-N03); the merchant gets the summary (orders created, partly converted, untouched).
- **Cancellation.** The customer cancels their open pre-order at any time, the merchant too (BR-O02); a partly converted one cancels only what is left, its converted orders live on. A pre-order converted in full cannot be cancelled.
- **Reports.** Pre-orders are left out of the overview counts (D5); they never reach sales (only delivered orders do).
---

## H2. Returns (المرتجع) — V2 Task 01 (D20)

### BR-R01 — Only Delivered Orders Take Returns

A return records goods the customer brings back **after delivery**. Before delivery the merchant cancels the order instead (BR-O02). There is no return window (D75): any delivered order can take returns. Returns are recorded by the merchant from the dashboard; customers cannot request them from the storefront.

### BR-R02 — Never More Than Delivered

Per order line, the returnable quantity is the delivered quantity (`delivered_quantity`: less than ordered after a partial delivery, BR-O13; a removed line has none, BR-O12) minus what live returns already brought back (selling units). A return line above it is rejected (`422 return_exceeds_delivered`, each offending line listed). A line appears once per return. Two returns recorded at the same time queue on the order row lock, so the last unit is never returned twice.

### BR-R03 — Returned Goods Go Back Into Stock

`On Hand += quantity × conversion_factor`, written as a positive `return` movement linked to the order (BR-INV06). Reserved is unchanged.

### BR-R04 — Return Price and Refund

A returned line is priced at the order line's snapshot `unit_price` (D74), never re-priced by tier. When the order has an order discount (BR-PR08), the line returns at that price less the discount's share (`× order_total / items_total`), rounded half-up per return line; the return that brings the last units of the order back takes exactly what is left of the order total, so returns never add up to more than it (D85). The order's `returned_total` rises by the return's total and its **net total** (`order_total − returned_total`, BR-O08) drops. If the order was already collected beyond the new net total, the difference goes back to the customer in cash: the merchant must confirm it (`refund: true`, otherwise `422 refund_required` with the amount and nothing is saved), and it is recorded as a `refund` collection tied to the return. Keeping it as customer credit instead is Task 02.

### BR-R05 — Returns in Totals and Reports

- What a customer owes, the statement, the outstanding report and the overview are net of returns (D59).
- Sales report: gross = delivered orders by `delivered_at`; returns = live returns by `returned_at`; net = gross − returns, on Cairo days (D60).
- Each order numbers its returns 1, 2, …; the status history gets a note «مرتجع #n» (no status change, no merchant notification).
- The customer sees the live returns of their order on "my orders", without the merchant's reason.

### BR-R06 — Voiding a Return

A wrong return is voided with a reason, never deleted. Voiding takes the goods off the stock again (a negative `return` movement) and lowers `returned_total`. A return whose cash refund is still live is voided only after the refund (`409 return_has_refund`, like D7). Goods sold or reserved since cannot leave the stock again: `422 insufficient_stock` (D76); the merchant adjusts stock first. A second void is `409 already_voided`.

Between voiding a refund and voiding its return, the order is collected beyond its net total; its status is `collected` and it owes nothing.

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

Method in V1 is always `cash`. A record is a `payment` (money received) or, from V2 Task 01, a `refund` (cash paid back after a return, BR-R04); both keep a positive amount.

### BR-C03 — Collection Status Is Derived

`collected_amount = Σ live payments − Σ live refunds` (refunds: BR-R04)

| Condition                        | Status              |
| -------------------------------- | ------------------- |
| net_total = 0                    | Collected           |
| collected_amount = 0             | Unpaid              |
| 0 < collected_amount < net_total | Partially Collected |
| collected_amount ≥ net_total     | Collected           |

`net_total = order_total − returned_total` (BR-O08). Collecting more than the net total is rejected. Voiding a payment that would leave more refunded than collected is rejected (`409 void_refund_first`).

### BR-C04 — Collection Is Independent of Order Status

Order status and collection status are separate.

Example:

`Order = Delivered`, `Collection = Unpaid` is valid.

Collections can be recorded at any order status except `Cancelled`. A pre-order takes no collection or credit (`409 is_preorder`, D88); its converted orders are collected as usual.

The "mark Delivered" action in the dashboard offers a shortcut "and collected full amount" to create both changes in one step.

### BR-C05 — Credit Limit (V2 Task 02)

Each customer may have a credit limit (`customers.credit_limit`; null = no limit; new customers start with the store default `default_credit_limit`). At checkout, the customer's **exposure** (D77: what they owe on delivered orders, net of returns and collections, plus the net totals of their open orders, minus their unused customer credit) plus the new order's total may reach the limit but not pass it; otherwise checkout fails with `422 checkout_failed` and `details.credit { limit, exposure, order_total }` (D78). Two checkouts of one customer queue on the customer row, so they cannot both fit under the limit. Changing the limit is audited (`customer.credit_terms_changed`).

### BR-C06 — Payment Terms, Due Date and Overdue

Each customer may have payment terms in days (`payment_terms_days`, 0–365; null = due on delivery; new customers start with `default_payment_terms_days`). On delivery the order gets `due_at = delivered_at + terms` — a snapshot: changing the terms later does not move existing due dates. An order is **overdue** when it is delivered, past `due_at` (orders delivered before V2 have none and fall due on delivery) and still has a net balance. The statement summary, the outstanding report (aged by days past due: not due yet, 1–30, 31–60, 61–90, over 90) and the overview show what is overdue.

### BR-C07 — Customer Credit

When a return leaves an order collected beyond its net total (BR-R04), the merchant may keep the difference as **customer credit** instead of paying it back: a `refund` collection with method `credit` lowers the order's collected amount as a cash refund would, and a `customer_credits` row of the same amount is created; no cash moves. The merchant applies credit to any later order of the same customer, as a `payment` collection with method `credit` (never more than the credit's remaining nor the order's balance; two uses of one credit queue on its row). Voiding a credit payment gives the amount back to the credit. A credit is voided (together with the refund that created it) only while none of it was applied.

### BR-C08 — Account Statement

The customer statement is a dated ledger of everything that changed what the customer owes: delivered orders (debit), returns (credit), payments (credit; `credit_applied` when drawn from credit), cash refunds (debit), returns kept as credit (`refund_to_credit`, debit) and the credit they create (an info line). Voided rows are left out. Rows are ordered by date with a running balance (positive = the customer owes the store); a date range (Cairo days, D60) starts from the opening balance of everything before it. The merchant sees it on the customer page with print; the customer sees the same rows in `/account/statement`. With no credit left, the closing balance equals what the customer owes (D59).

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

1. Send the OTP via **WhatsApp**: from the store's own number when the tenant has linked one and it is connected, otherwise from the platform number (D70). A store-number failure other than "not on WhatsApp" is retried once from the platform number.
2. If the WhatsApp provider reports the number is not reachable on WhatsApp (or the send fails), send the OTP via **SMS** automatically.
3. The customer can also tap "send by SMS instead" after 60 seconds.

OTP rules:

- 6 digits, valid for 10 minutes, single use.
- Maximum 5 OTP sends per phone per hour, maximum 5 verification attempts per OTP.
- OTP sends are logged (channel, provider message id, status) per tenant.
- OTP, customer invitations (BR-CU08) and order status messages (BR-N03, V2) are the only customer-facing WhatsApp/SMS uses (BR-N01). Order status messages have their own per-phone limit, so they never use up the OTP sends (D79). Operator alerts (D65) go to the platform operator, never to customers.

### BR-CU08 — Merchant-Created Customers and Invitations

The merchant can create a customer from the dashboard with name, phone and optional addresses.

Then the merchant can **invite** the customer:

- An invitation message with a one-time link is sent via the same channel order as BR-CU07 (WhatsApp, then SMS).
- The link opens the storefront, lets the customer set a password, and marks the phone as verified (the link itself proves phone ownership).
- Invitation link is valid for 7 days and can be re-sent.
- The merchant may word the message themselves in dashboard settings (D72), with the placeholders `{اسم_العميل}`, `{اسم_المتجر}`, `{عدد_الأيام}`; the link is always appended on its own last line.
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

V2 (Task 10, BR-O14): the cart holds order lines and pre-order lines side by side (`kind`), each SKU once per kind. Checkout takes only the order lines; the pre-order confirmation only the pre-order lines.

---

## K. Notifications

### BR-N01 — Channels

- Email is out of scope.
- WhatsApp and SMS reach customers for OTP and invitations (BR-CU07, BR-CU08) and, since V2 Task 03, for order status messages (BR-N03). Nothing else: no marketing or broadcast sends (D70).
- Order status messages go by WhatsApp only (store number, then platform number) and never fall back to SMS (D80).

### BR-N02 — In-App Notifications

The merchant gets in-app (dashboard) notifications for:

- New order.
- Customer cancellation.
- An unconfirmed order the system cancelled after `pending_expiry_hours`: `order_expired` (V2 Task 04).
- A customer's pre-order: `new_preorder` (V2 Task 10, BR-O14).
- The store WhatsApp number dropped (logged out on the phone): `whatsapp_dropped`, linking to settings to scan the QR again (V2 Task 03).

Customers get no in-app notifications; they see the status on "my orders" and receive the WhatsApp messages of BR-N03 (D6).

### BR-N03 — Order Status Messages (V2 Task 03)

- When the merchant moves an order to a status in the store's `order_notify_statuses` list, the customer gets a WhatsApp message about it. The list can hold `confirmed`, `preparing`, `out_for_delivery`, `delivered`, `cancelled`, from V2 Task 08 `discount` (the merchant changed the order's discounts; the message gives the new total, D85), and from V2 Task 09 `edited` (the merchant changed the order's lines, with the new total, D87); a new store starts with all but `preparing` and `discount`. `edited` was also added to every store that already notified something.
- The merchant words each status in settings (`order_status_messages`), up to 500 characters, with the placeholders `{اسم_العميل}`, `{اسم_المتجر}`, `{رقم_الطلب}`, `{حالة_الطلب}`, `{الإجمالي}`, plus `{سبب_الإلغاء}` in the cancelled text only. Any other `{…}` is rejected. No wording (or the default sent back) = the platform default text.
- A cancel tells the customer only when the merchant cancels; a customer cancelling their own order gets nothing.
- V2 Task 10 (D88): each normal order converted from a customer's pre-order sends the `preorder_converted` text (with the new order's number and total) whatever `order_notify_statuses` says, like `placed`; the store may word it in `order_status_messages`; the customer's opt-out applies.
- The message is sent after the change commits: a change that rolls back sends nothing. A customer over their order-message limit (D79) is skipped and logged, and the change still succeeds.
- A customer can turn the messages off from their storefront account ("رسائل واتساب عن طلباتي", `customers.order_notifications_opt_out`). OTP and invitations are not affected.
- The order page lists the messages sent about the order (purpose, channel, status, time).
- The demo store (`tenant:demo`) starts with an empty list: its customers have invented phones.

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
- `stale_pending_hours`: when a `Pending` order is highlighted as stale (default 24, BR-INV07, D10).
- `pending_expiry_hours`: when an unconfirmed order cancels itself (null = off, the default; 1–720, never below `stale_pending_hours`; BR-INV07, D81).
- `invitation_message`: the merchant's wording of the customer invitation (null = platform default, BR-CU08, D72).
- `default_payment_terms_days`, `default_credit_limit`: the credit terms a new customer starts with (null = none; BR-C05, BR-C06).
- `order_notify_statuses`: the order statuses customers are told about by WhatsApp (default confirmed, out for delivery, delivered, cancelled; BR-N03).
- `order_status_messages`: the merchant's wording per status (a missing entry = platform default; BR-N03).
- `max_discount_percent_sales`: the most a sales user may take off an order line, in % (null = sales users cannot discount, the default; BR-PR08, D85).
- Store WhatsApp number linked by QR (D70; kept in `whatsapp_connections`, not in the settings row).

---

## N. Subscription

Subscription billing is not implemented in V1.

The tenant has an operational status:

- Active
- Suspended

Actual subscription plans, invoices, renewals, and payment collection are future scope.
