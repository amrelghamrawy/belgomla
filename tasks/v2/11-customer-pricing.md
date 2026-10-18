# V2 Task 11 — Customer groups, wholesale levels and customer-specific prices

Status: **planned**. Requires V2 Task 05. Read `CLAUDE.md`, `rules/*.md`, then this file.

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
