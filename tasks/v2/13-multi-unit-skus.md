# V2 Task 13 — Multiple selling units per SKU (piece / carton)

Status: **done** (2026-10-29, commit: see log). A SKU is sold in one to ten units (`sku_units`: unit, factor, base price or %, cover price, MOQ, tiers, one default, archived not deleted); stock stays per SKU in base units. Two migrations: step 1 adds `sku_units` and backfills one default unit per SKU (tiers, cart lines, order lines and negotiated prices pointed at it), step 2 drops the SKU's price columns and `sku_price_tiers.sku_id`; both roll back. `PriceResolver::resolve(SkuUnit, qty, ?context)` prices each unit on its own (group % and promotions on every unit's base price, negotiated prices per unit). Catalog `SaveSkuUnits` writes the units from `sku.units[]` (create, update by id or unit, archive the rest, one default); pricing per unit (`PUT products/{p}/units/{skuUnit}/pricing`, recompute every unit, bulk pricing per unit with an optional `unit_id`). Cart lines, merchant order/quote/edit lines and pre-orders are SKU units (`sku_unit_id`); checkout sums a SKU's lines in base units and locks per SKU; snapshots keep the unit name, factor and `sku_unit_id`. Low stock by the smallest active unit. Import/export format v2 (one row per unit, v1 still read). Storefront `sku.units[]` with a unit picker on the product page and cards, the cart showing the unit per line, the price list one row per unit; dashboard «وحدات البيع» editor, unit selects on the new-order and edit forms, negotiated prices and bulk pricing per unit. D91. Requires V2 Tasks 06, 11, 12. Read `CLAUDE.md`, `rules/*.md`, then this file.

## Delivered (deviations from the plan below)

- **Flat SKU fields kept as the default unit's:** the dashboard and storefront `sku` keep `unit`/`selling_unit`, `conversion_factor`, `moq` and the prices as the default unit's (lists, cards and the product picker need one unit), next to the new `sku.units[]`; the storefront `sku.in_stock` is true when any unit can be ordered, each unit has its own `in_stock` (D37 reworded). The product *payload* has no flat fields any more (`sku.units[]` only).
- **Cart add accepts `sku_id` alone** as the SKU's default unit (besides `sku_unit_id`), so existing callers (the demo seeder, the e2e shopper helper) keep working; PATCH/DELETE address the line by its unit id. Merchant quote/order/edit lines take `sku_unit_id` only (`distinct`), with a unit select in the forms.
- **Shared stock is checked everywhere a SKU can appear twice:** the cart counts what it already holds of the SKU in other units (`CartQuery::heldBesides`), checkout/quote/edit sum the lines in order (a line that no longer fits is refused with what is left in its own unit), pre-order conversion takes lines of one SKU from one stock. `checkout_failed` / `cart_line_invalid` items and quote problems carry `sku_unit_id` and are keyed by it.
- **Archiving:** a unit left out of the product form is archived, never deleted; sending its unit again restores it with its prices. An archived unit is refused like an inactive product (`product_inactive`) and drops out of the cart on refresh. `ArchiveUnit` counts SKUs selling in the store unit through active SKU units.
- **Pricing errors per unit:** `SaveSkuUnits` moves a pricing refusal's keys under `sku.units.{i}.` (`invalid_tiers`, `cover_price_required` too) so the form and the import point at the right unit. Price audit records (`sku.price_changed`, …) are on the unit (`sku_units`); `product.updated` lists `sku.units` (unit, factor, default).
- **Pre-order cap in the default unit, demand in base units:** `PreorderDemandQuery` adds `quantity_base` and `remaining()`; the storefront `preorder.remaining` and the dashboard demand use it. `preorder_limit` keeps its meaning for single-unit products.
- **Import/export v2:** a SKU's units are consecutive rows with its code (product, base unit, stock and custom fields read from the first row; `unit_is_default` نعم/لا); a unit the file does not list is **kept** (an import never archives units); `total`/`processed`/counts are per SKU and each error names its own row. v1 files (`products_v1`, `selling_unit`) still import as single-unit SKUs (D95 a extended).
- **Order lines:** `order_items.sku_unit_id` is backfilled for every old line (each SKU had exactly one unit), so old orders can still be edited; nullable as planned.
- **Demo data:** the A4 notebook (ST-006) also sells by the carton of 24 at 10% off the piece price, so `tenant:demo` shows the feature; the other demo SKUs are unchanged. The price-list print baseline was re-recorded (the unit column now shows the factor).
- **Not in this task (D91 h):** buying units, conversions between non-base units, per-unit stock, a unit per customer group; the merchant cannot reorder units other than by the form order.
- Tests: backend 5444 passed (+140: migration test of both steps and their rollbacks on a V1-shaped catalog; `SkuUnit` model; `SaveSkuUnits`; resolver per unit with group/promotion/negotiated price; checkout validator and checkout with piece + carton sharing stock, MOQ per unit, insufficient per line, archived unit, snapshots and `quantity_base`; cart, quote, merchant order and edit by unit; pre-order cap in base units and conversion across units; per-unit pricing endpoint, recompute, bulk `unit_id`; low stock by the smallest unit; products endpoint `sku.units` validation and archive/restore; import v1 and v2, round trip with a multi-unit SKU; storefront and price list per unit; permission matrix and OpenAPI shapes). Frontend 891 unit tests (+18); new e2e «a shopper buys 2 cartons and 5 pieces of one SKU» on mobile and desktop. Full e2e on a production build (`next build` + `next start`): `dashboard` and `preorders` specs updated to the units editor and payload, `order-editing` now names its dialog (the product picker's popover is also a dialog), price-list print baseline re-recorded; all 48 green (`discounts` failed once at login and passed on re-run).
- Smoke against `php artisan serve` (gateway off, no queue worker): a product sold by the piece (12.00, MOQ 5, default) and the carton of 12 (130.00, 5+ → 125.00) with 100 pieces; the storefront listed both units in stock; a merchant order for 2 cartons + 5 pieces snapshotted «كرتونة ×2 (12)» and «قطعة ×5», reserved 29; delivered → `sale` −24 and −5, on hand 71, reserved 0; no outbound message created. The smoke product was set inactive afterwards.

## Goal

One SKU can be sold in several units, for example a notebook by the piece (factor 1, base price 12) and by the carton of 48 (factor 48, its own price and tiers). Stock stays in the base unit, so both units draw from the same stock. In V1 the merchant had to create two SKUs (BR-U02).

Out of scope: buying units (purchase), unit conversions between non-base units, and per-unit stock.

## Read before coding

| What      | Where |
| --------- | ----- |
| Rules     | BR-U01..U05, BR-MOQ01/MOQ02, BR-PR01..PR05, BR-INV02, D36 (low stock), D37 (`in_stock`) |
| Tables    | `07-domain-model.md` §3 `skus`, §4 `sku_price_tiers`, §5 `cart_items`, §7 `order_items`, §15 (hook: `selling_unit_id` → `sku_units`) |

## Left by earlier tasks

- `skus`: `selling_unit_id, base_unit_id, conversion_factor, cover_price, base_price, base_price_percent, moq`; `sku_price_tiers(sku_id, min_quantity, unit_price, discount_percent)`.
- Every place that multiplies by `conversion_factor` must become unit-aware: `Orders/Data/CheckoutLine.php`, `Orders/Services/CheckoutValidator.php`, `Orders/Services/OrderSnapshots.php`, `Inventory/Http/Resources/InventoryRowResource.php` (low stock), `Inventory/Queries/LowStockQuery.php`, `StoreProductResource` (`in_stock`). Re-grep `conversion_factor` at the start.
- `cart_items` unique `(cart_id, sku_id)` (with `kind` from Task 10). `order_items` snapshot `unit_name, conversion_factor, quantity, quantity_base`.
- Task 06 import format v1. Tasks 11/12: `PricingContext`, groups (% off base), customer prices per SKU, promotions per SKU.

## Deliverables

### 1. Docs

D91 into 06. BR-U02 is rewritten (several units per SKU), and BR-U04/MOQ01/PR01..PR05 are worded per unit. 07 §3/§4 get the new table, §15 drops the row. 08 gets the shapes.

### 2. Schema (migration with backfill)

- `sku_units`: `id, tenant_id, sku_id FK, unit_id FK units, conversion_factor int CHECK >= 1, base_price numeric(12,2) CHECK > 0, base_price_percent, cover_price null, moq int CHECK >= 1, is_default boolean, sort_order, status (active|archived)`. Unique `(sku_id, unit_id)`; partial unique on one default per SKU.
- `sku_price_tiers.sku_unit_id FK` (it replaces `sku_id` as the owner; `sku_id` is kept during the migration, then dropped).
- Backfill: one default `sku_units` row per SKU from its current columns, with its tiers moved. Then drop `skus.selling_unit_id, conversion_factor, cover_price, base_price, base_price_percent, moq` in a second migration after the code switch (two-step, so a rollback is possible).
- `cart_items.sku_unit_id FK` (backfilled to the default unit); unique becomes `(cart_id, sku_unit_id, kind)`.
- `order_items.sku_unit_id null` (old rows keep their snapshots; the snapshot columns already exist).
- `customer_sku_prices.sku_unit_id`, `promotion_skus` unchanged (a % applies to every unit's base price).

### 3. Module classes

- Catalog: model `SkuUnit` (relations `tiers`). `Sku::units()`, `Sku::defaultUnit()`. `ProductData.sku.units[]` replaces the flat fields in one go. The dashboard is the only client, so there is no compatibility period. Actions `CreateProduct`/`UpdateProduct`/`DuplicateProduct` handle the units list. `ArchiveUnit`'s `UnitInUse` checks `sku_units`.
- Pricing: `PriceResolver::resolve(SkuUnit, qty, ?ctx)`. `UpdateSkuPricing` works per unit. `BulkApplyPricing` applies to every unit (or `unit_id` filter). `PricingContext` customer prices are keyed by `sku_unit_id`.
- Inventory: stock stays per SKU in base units. `LowStockQuery` uses the **smallest** active unit's `moq × factor` (D36 reworded).
- Customers cart: lines by `sku_unit_id`. `CartPricer` per unit.
- Orders: `CheckoutLine` from the unit; `quantity_base = quantity × unit factor`; snapshots `unit_name` and `conversion_factor` from the unit; `sku_unit_id` stored. Locks are still per SKU (two units of one SKU in one order lock once; sum their base quantities in the stock check).
- Import/export (Task 06): format v2, one row per SKU **unit** (`unit_is_default` column). v1 files are still accepted as single-unit.

### 4. Endpoints

The dashboard product payload has `sku.units[{id?, unit_id, conversion_factor, base_price|base_price_percent, cover_price, moq, tiers[], is_default}]`. The store product resource has `sku.units[{id, name, conversion_factor, moq, prices…, in_stock}]`. The cart add takes `sku_unit_id`. Pricing endpoints are per unit (`PUT products/{product}/units/{unit}/pricing`). OpenAPI and types follow.

### 5. Frontend

- Product editor: a "وحدات البيع" table (unit, factor, price/%, MOQ, tiers editor per unit, default radio).
- Storefront: a unit picker (segmented control) on the product page and quick-add, with the price/MOQ/tiers switching with it. The cart shows the unit per line, and the same SKU can appear in two units.
- Price list: one row per unit.

## Test list

- Migration test: the backfill keeps every price, tier and MOQ; a second migration drops the columns (run on a seeded DB).
- `Unit/Services/PriceResolverTest`: per-unit tiers; group % and promotions apply per unit's base price; customer price per unit.
- `Unit/Services/CheckoutValidatorTest`: piece + carton of one SKU share the stock (sum of base qty); MOQ per unit; insufficient stock reported per line.
- `Unit/Actions/CheckoutTest`: snapshots carry the unit name and factor; `quantity_base` CHECK holds.
- `Unit/Queries/LowStockQueryTest`: smallest unit rule.
- `Unit/Actions/CreateProductTest`/`UpdateProductTest`/`DuplicateProductTest`: units created, edited, archived; one default.
- `Unit/Jobs/ImportCatalogTest`: v1 and v2 files.
- Every existing test touching `conversion_factor` on the SKU is migrated (grep list at the start).
- Frontend: unit picker, editor table; e2e: buy 2 cartons + 5 pieces of one SKU.

## Slices

1. Docs + additive schema + backfill.
2. Catalog/Pricing on `sku_units`.
3. Cart, checkout, snapshots, inventory.
4. Import/export v2.
5. Frontend, then the drop-columns migration.

## Definition of done

Green suites; curl: product with piece + carton → storefront shows both → order both → stock deducted in base units once delivered; demo data still loads (`tenant:demo`); docs updated.

## Open decisions (proposed)

- **D91** Units per SKU: each unit has its own price, %, cover price, MOQ and tiers; one unit is the default (shown first, used by the price list sort). Stock and low-stock stay per SKU in base units, and low stock uses the smallest unit's MOQ. Group discounts and promotions apply to every unit; negotiated customer prices are per unit.
