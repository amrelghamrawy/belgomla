# V2 Task 13 — Multiple selling units per SKU (piece / carton)

Status: **planned**. Requires V2 Tasks 06, 11, 12. Read `CLAUDE.md`, `rules/*.md`, then this file.

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
