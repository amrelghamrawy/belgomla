# V2 Task 11 — Customer groups, wholesale levels and customer-specific prices

Status: **done** (2026-10-27, commit: see log). Customer groups (`customer_groups`, one % off the base price, 0–90, archived not deleted) and prices negotiated per customer and SKU (`customer_sku_prices`); `customers.customer_group_id`; `order_items.price_source`. `Pricing\Data\PricingContext` read once per request by `Customers\Queries\PricingContextQuery` (one query); `PriceResolver::resolve(sku, qty, ?context)` applies D89 (negotiated price wins, else the lower of the normal price and the group price) and says where the price came from; `PriceResolver::listed()` is what the storefront shows. Every price follows it: product list and page, price list and its Excel file, cart, checkout, pre-orders and their conversion, the merchant's quote and order, order edits. Dashboard: `/api/dashboard/customer-groups` (list, create, PATCH incl. restore, archive, bulk assign by ids or the list filter), `/api/dashboard/customers/{id}/prices[/{sku}]`, the group on the customer form and list (`?group=`), permission `pricing.customers` (owner, manager). Frontend: «مستويات العملاء» page, group column/filter and bulk assignment on the customers list, group select and «أسعار خاصة» tab on the customer page, «سعرك الخاص» on the storefront card, product page, cart and price list, the price source on dashboard order lines and quotes. D89. Requires V2 Task 05. Read `CLAUDE.md`, `rules/*.md`, then this file.

## Delivered (deviations from the plan below)

- **Storefront shape:** as planned, `sku.base_price`, `discount_percent` and `tiers` are simply the customer's own (a negotiated price has no tiers, a group price keeps only the tiers below it) and `price_source` appears only for `group` / `customer`. Cart lines, quote lines and order items always carry `price_source` (`base | tier | group | customer`), so the cart can badge its lines too.
- **Catalog gets the context through a contract:** Catalog may not depend on Customers, so `Pricing\Contracts\CustomerPricing` (filled by `PricingContextQuery`, bound in `CustomersServiceProvider`) gives the storefront controllers and `PriceListExporter` the shopper's context. `PriceListQuery` has no cache, so it does not take the context; it now loads `sku.tiers` and the row resource / exporter price each row (`PriceListQuery takes the context` became "the price list rows take it").
- **`PriceResolver::resolve()`'s context stays optional** (`null` = V1) for the dashboard's own displays (product form tiers, pricing); every customer-facing caller passes it: `CartPricer` (via `RefreshCart`), `CheckoutValidator::check/checkLines/checkPreorder` (context required as a parameter; `PlaceOrder`, `OrderQuoter` and `EditOrderLines` build it for the order's customer, `ConvertPreorders` through `PlaceOrder`). The quote lists the customer's tiers (`listed()`).
- **A 0% group** is allowed (a level without a discount) and changes no price; a group price never drops below 0.01.
- **Restore:** archiving has its own endpoint; `PATCH … {status: "active"}` brings a group back (its members' prices return with it). Nobody can join an archived group (`409 group_archived`, the one new error code); a customer already in it keeps it.
- **Assignment:** `{ customer_ids }` (≤ 500; an id of another store refuses the whole call with `422` on `customer_ids`) or `{ filter: { q, status, group } }` (not empty, like BR-P12); customers already in the group are skipped; one `customer_group.assigned` record plus one `customer.group_changed` per customer under one batch id. The customer form sets the group through the same `SetCustomerGroup` step (create and update take `customer_group_id`, gated by `pricing.customers` in the request's `authorize()`, so sales may edit a customer but not their group).
- **Negotiated prices:** `PUT` answers `201` for a new price and `200` when it replaces one (sending the same price and note writes nothing); `DELETE` of a missing price is `404`. Audit records are on the customer (`customer.price_set`, `customer.price_removed`).
- **`order_items.price_source`** is backfilled `tier` where `tier_min_quantity` is set, else `base`. The dashboard order page and the merchant's quote name a group or negotiated price under the unit price.
- Not in this task (D89 h): group-specific tiers, time-limited prices (Task 12), catalogs per group, and an import/export column for group or customer prices.
- Tests: backend 5080 passed (+250; unit, HTTP, arch: precedence dataset, one-query context, every caller, actions with audit and tenant isolation, endpoints, permission matrix rows), frontend 843 (+20); e2e "BR-PR14 a shopper put in a group sees and pays the group price" on mobile and desktop. `price-list.spec.ts` print snapshot still fails on its date line only (pre-existing).
- Smoke against `php artisan serve`: two groups (10%, 25%), three new shoppers: A in the 10% group by ids (100.80 for a 112.00 book on the price list, in the cart, the order and the dashboard order, source `group`), B in the 25% group by the list filter (84.00), C with a negotiated 113.00 above the base price (113.00, source `customer`); after archiving A's group and removing C's price both see 112.00 again, B still 84.00; a guest is 401 on the groups. The smoke groups were archived and the three orders cancelled afterwards.

## Goal

Not every retailer pays the same price. The merchant can:

1. Define **customer groups** (levels such as "موزع", "مكتبة كبيرة", "مكتبة صغيرة"), each with a default percentage off the base price, and assign customers to them.
2. Set a **negotiated price** for one customer on one SKU.

Every price the customer sees (store, cart, checkout, price list) follows these rules. Customers not in a group and guests see today's prices.

Out of scope: hiding products per group (customer-specific catalogs are deferred), group-specific tiers, time-limited prices (Task 12).

## Read before coding

| What      | Where |
| --------- | ----- |
| Rules     | BR-PR01..PR07, BR-PR10/PR11, BR-P13, BR-CU04 (`require_login_for_prices`) |
| Tables    | `07-domain-model.md` §4 pricing, §5 customers |
| Decisions | D1, D27, D28, D34, D53 |

## Left by earlier tasks

- `Pricing\Services\PriceResolver::resolve(Sku, qty)` is "the single price authority"; it never queries and requires `tiers` loaded. Its callers include `Customers\Services\CartPricer`, `Orders\Services\CheckoutValidator`, the store product resources and `PriceListQuery`/`PriceListRowResource`, plus Task 07's quote and Task 09's re-pricing.
- `Catalog\Queries\FacetQuery` is cached per tenant + selection, and `PriceVisibilityQuery::hiddenFor`.
- Task 06: the import/export format (a group price column can be added later; not in this task).

## Deliverables

### 1. Docs

D89 into 06. New BR-PR12 (groups), BR-PR13 (customer prices), BR-PR14 (precedence). Roadmap V2 items marked. 07 §4/§5 get the new tables. 08 gets the endpoints.

### 2. Schema

- `customer_groups`: `id, tenant_id, name (unique per tenant), discount_percent numeric(5,2) CHECK 0..90, status (active|archived), sort_order`, timestamps.
- `customers.customer_group_id FK null`.
- `customer_sku_prices`: `id, tenant_id, customer_id, sku_id, unit_price numeric(12,2) CHECK > 0, note`, timestamps. Unique `(customer_id, sku_id)`.

### 3. Module classes

- Pricing:
  - `Data\PricingContext` (`?groupDiscountPercent`, `array<skuId,string> customerPrices`) is built once per request by `Customers\Queries\PricingContextQuery::for(?Customer)`. The resolver stays DB-free.
  - `PriceResolver::resolve(Sku, qty, ?PricingContext $ctx = null)`: precedence (D89): (1) the customer's negotiated price for the SKU, if set, wins outright; (2) otherwise take the normal result (base or tier), and if the customer has a group, the price is `min(normal result, base × (1 − group%))`. The result carries a `source` (`base|tier|group|customer`). `discountPercent` stays relative to the cover price.
  - Every caller passes the context. The arch test "orders price only through the PriceResolver" stays.
- Customers: `CreateCustomerGroup`, `UpdateCustomerGroup`, `ArchiveCustomerGroup` (allowed with customers in it: an archived group stops applying, and its customers keep the id), `AssignCustomersToGroup` (bulk ids or filter), `SetCustomerSkuPrice`, `RemoveCustomerSkuPrice`. Each is audited.
- Catalog: `FacetQuery`'s cache key is unchanged (facets have no prices). `PriceListQuery` takes the context; the price list cache (if any) is keyed by group + customer.
- Orders: `order_items.price_source varchar(10)` snapshot (display on the dashboard).
- Permission `pricing.customers` (owner, manager) in the Task 05 map.

### 4. Endpoints

- `GET|POST /api/dashboard/customer-groups`, `PATCH /customer-groups/{id}`, `POST /customer-groups/{id}/archive`, `POST /customer-groups/{id}/assign`.
- `GET|PUT|DELETE /api/dashboard/customers/{id}/prices[/{sku}]`.
- Customer resources show the group. Store product/price list resources are unchanged in shape: they simply show the customer's price, plus `price_source` when not `base|tier` (to show a "سعرك الخاص" badge).

### 5. Frontend

- Dashboard: a "مستويات العملاء" page (CRUD, bulk assign from the customers list), a group select on the customer form, a "أسعار خاصة" tab on the customer page (SKU search, price, note).
- Storefront: a "سعرك الخاص" badge where the source is group/customer. The price list shows the customer's prices.

## Test list

- `Unit/Services/PriceResolverTest`: BR-PR14 precedence dataset — no context = V1 results; group lower than base, tier lower than group, the customer price beats both even when higher (D89); rounding (D27); archived group ignored.
- `Unit/Queries/PricingContextQueryTest`: one query for the context, guests null.
- `Unit/Services/CartPricerTest`, `CheckoutValidatorTest`: the customer price flows to the order snapshot with `price_source`.
- `Unit/Actions/*Group*Test`, `SetCustomerSkuPriceTest`: audit, tenant isolation, validation.
- `Http/Store/ProductsTest`, `PriceListTest`: two customers see different prices; a guest sees base; prices hidden still hidden.
- `Http/Dashboard/Customers/GroupsTest`, `PricesTest`, permission matrix rows.
- Frontend: badge, group page; e2e: assign group → storefront price changes → checkout snapshot.

## Slices

1. Docs + schema.
2. `PricingContext` + resolver + all callers (no UI yet).
3. Group and customer-price actions + endpoints.
4. Frontend.

## Definition of done

Green suites; curl as two customers of different groups + one with a negotiated price, cart and checkout prices verified; docs updated.

## Open decisions (proposed)

- **D89** Price precedence: a negotiated customer price for the SKU always applies (even above the normal price, it is what was negotiated). Otherwise the customer gets the lower of the normal price (base or tier) and the group price (base − group %). Groups give a single % off base, not their own tiers.
