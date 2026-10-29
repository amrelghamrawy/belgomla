# V2 Task 15 — Bundles and full-grade sets

Status: **done** (2026-10-29, commit: see log). A bundle (`products.type = bundle`) is one SKU sold in one unit (factor 1, its own code and MOQ) made of component SKU units with fixed quantities (`bundle_items`), priced fixed or at the components' base-price sum less a % (`bundle_pricing`; the price is stored as the bundle unit's base price, so groups, promotions and negotiated prices apply as to any SKU, and `RepriceBundles` follows a component's price change). No own stock: `BundleAvailability` = min over components of available ÷ (quantity × factor). Endpoints `POST /api/dashboard/bundles`, `PUT /bundles/{product}`, `POST /bundles/generate` (`GenerateGradeSet`: a filter → an inactive draft «طقم …», `SET-{n}`, at the plain sum). Orders keep a bundle line (`is_bundle`, priced, holds nothing) and one child line per component (`parent_item_id`) with the price split by the components' own prices (`App\Support\Allocation`, half-up, remainder on the last) and out of `items_total`; checkout, quote, merchant orders and edits check/reserve the components together with loose lines (shortfall reported on the bundle line); partial delivery in bundles; returns of component lines at their allocated price; a line discount on the bundle line re-splits its children. Storefront `type`, `components[]`, `availability`, `components_total`, `saving`; cart and quote lines `is_bundle`; order items `is_bundle` / `parent_item_id`. Inventory lists, low stock and bulk pricing skip bundles; export writes a `bundles_v1` sheet, import refuses bundle codes; `GET /api/dashboard/reports/bundles`. Frontend: «باقة» editor (component picker, quantities, fixed / % pricing with live sum and saving, draft activation), «إنشاء باقة صف دراسي» wizard, «وفّر» cards, components with stock on the product page, expandable bundle lines on both order pages, the bundle report card. Demo: «باقة الأضواء أولى إعدادي» (SET-DEMO-1). D93. Requires V2 Tasks 01, 13, 14. Read `CLAUDE.md`, `rules/*.md`, then this file.

## Delivered (deviations from the plan below)

- **Price is materialised:** `BundlePricer` computes the bundle price and it is written as the bundle unit's `base_price` (through `SaveSkuUnits` / `UpdateSkuPricing`), so the `PriceResolver`, cart, price list and storefront need no bundle branch. A `percent_off_sum` bundle is repriced by `Pricing\Actions\RepriceBundles`, called from `UpdateSkuPricing` whenever a unit's base price changes (product form, per-unit pricing, bulk pricing, recompute, import), audited `sku.price_changed` on the bundle unit with the caller's batch. 0 % is allowed (a grade set at the plain sum).
- **Allocation:** the bundle's price *per bundle* is split over its components in proportion to what each would cost the customer on its own (resolved at its quantity in one bundle); a child's `line_total` = its share × the bundles (the children add up to the bundle line exactly), its `unit_price` = the share per unit (half-up, ≥ 0.01), which is what a return of it gives back; the last return of an order still absorbs the rounding (D85). No DB CHECK ties a child's `line_total` to `quantity × unit_price`.
- **Stock check order:** loose lines are checked before bundle lines so a shared shortfall lands on the bundle line (`insufficient_stock` with the bundles that still fit; a withdrawn component is `product_inactive`). The **cart** checks a bundle against its own availability only (sharing with loose lines is checked at checkout).
- **Orders beyond the plan:** partial delivery names the bundle line in bundles (a child id is refused) and records `delivered_short` on the bundle line; editing changes / adds / removes the bundle line as a whole (a changed quantity releases the old child lines and writes new ones at the new price, keeping the line's manual discount); a line discount is allowed on the bundle line only and re-splits the children; the quote's `available` for a bundle is the bundles its components allow.
- **Refusals:** `PUT /products/{id}`, duplicate and `PUT …/units/{unit}/pricing` on a bundle are `409 is_bundle` (new error code); `PUT /bundles/{id}` on a simple product is `404`. Bundles are created active or inactive (no Coming Soon, no pre-orders). Required custom fields are not required of a bundle (`CustomFieldValidator` takes `required: false`).
- **History:** a SKU whose unit is a bundle component counts as having history (`SkuHistoryQuery`), so the product form sets it inactive instead of deleting it (the FK is NO ACTION).
- **Export / import:** XLSX exports add the `bundles_v1` sheet (one row per component; `Catalog\Services\BundleSheet`, `BundleExportQuery`), CSV leaves bundles out, the products sheet excludes bundles; an import row with a bundle code is a row error «الباقات لا تُستورد من الملف». `CatalogRowMapper` is untouched.
- **Report:** a separate `GET /api/dashboard/reports/bundles?from&to` (`reports.view`) instead of extending the sales report: bundles delivered (quantity, total) and component base units consumed.
- **Test helpers / files:** the task's checkout / transition / return tests live in `Unit/Services/CheckoutValidatorBundlesTest`, `Unit/Actions/BundleOrdersTest` (checkout snapshots, reservations, cancel, delivery, partial delivery, returns, edits, discounts) next to the existing files; `TenantTestCase::gradeSet()`, `bundleSku()`, `importedBundle()`, `bundleOrder()`.
- Tests: backend 5848 passed (+252: migration and rollback (and the Task 13 migration test now rolls this one back first), models `BundleItem`, `BundlePricing`, `OrderItem` children, `Product` type; enums; `Allocation`; `BundleAvailability`; `BundlePricer`; `CreateBundle` / `UpdateBundle`; `GenerateGradeSet`; `RepriceBundles`; checkout validator, checkout, delivery, partial delivery, returns, edits, discounts with bundles; dashboard `BundlesTest` (create, validation dataset, nesting, duplicate code, update, 404 simple, `is_bundle` refusals, tenant isolation, generate, permissions, cross-host session, guest); storefront `BundlesTest` (shape, missing component, group prices, hidden prices, cart limits, checkout); inventory / low stock / bulk pricing / history exclusions; export sheet, import refusal, round trip; bundle report endpoint and query; permission matrix and security sweep rows; demo seeder; key-list tests updated for `type`, `is_bundle`, `parent_item_id`). Frontend 991 unit tests (+49); new e2e «BR-P15 a merchant generates a grade set, activates it and a shopper buys it» on mobile and desktop (the wizard dialog was made scrollable: on a store with many facets its button sat below the viewport). Full e2e on a production build (`next build` + `next start`): all 52 green.
- Smoke against `php artisan serve` (gateway off, no worker; queued messages drained offline through the log driver, no real WhatsApp): generated «طقم أولى إعدادي - علوم» (SET-6) from category أولى إعدادي + «علوم» — inactive, 3 components, 2088.00, 27 available; activated; a merchant order of 2 sets snapshotted the bundle line (4176.00) and three child lines (160.00 + 176.00 + 3840.00) and reserved 2, 2 and 40 base units; delivered → `sale` movements on the components; one book (BK-010) returned at its allocated 80.00 (net 4096.00, stock back +1); the bundle report listed SET-6 × 2 = 4176.00. The smoke sets were set inactive afterwards.

## Goal

A retailer buying for a grade wants "every book of أولى إعدادي, first term, from الأضواء" in one click. The merchant defines a **bundle**: a sellable product made of component SKU units with quantities, at a fixed price or the components' sum minus a %. The bundle has no stock of its own; it is available as long as every component is. Ordering a bundle reserves and deducts the components.

Also a **set generator**: build a bundle from a facet selection (grade + term + publisher) in one step.

Out of scope: bundles inside bundles, optional/choice components, and bundle-level tiers (MOQ on the bundle is supported).

## Read before coding

| What      | Where |
| --------- | ----- |
| Rules     | BR-P02, BR-P04, BR-PR02 (no tier combination), BR-INV02..INV05, BR-O05 (snapshots), BR-R* (Task 01 returns), BR-PR14/PR15 (Tasks 11/12) |
| Tables    | `07-domain-model.md` §3, §6, §7 |
| Decisions | D17/D18 (school-book fields and facet order), D37 |

## Left by earlier tasks

- Task 13 `sku_units`, Task 14 variants. `FilteredProductsQuery` (selection by facets). `StockLedger::lock/reserve/release/deduct`, Task 01 `restock`. `PlaceOrder` (Task 07) and `EditOrderLines` (Task 09).

## Deliverables

### 1. Docs

D93 into 06. New BR-P15 (bundles) and a note in BR-INV02 (derived availability). 07 §3/§7 get the new tables/columns. 08 gets the shapes.

### 2. Schema

- `products.type varchar(10) default 'simple'` CHECK `in ('simple','bundle')`. A bundle product has one SKU and one unit (factor 1, its own code) but **no** `sku_inventory` row is used.
- `bundle_items`: `id, tenant_id, bundle_sku_id FK skus, component_sku_unit_id FK sku_units, quantity int CHECK >= 1, sort_order`. Unique `(bundle_sku_id, component_sku_unit_id)`.
- `bundle_pricing`: `bundle_sku_id PK, mode (fixed|percent_off_sum), value numeric(12,2)`.
- `order_items.parent_item_id null FK order_items` and `order_items.is_bundle boolean default false`. A bundle line has price and total; its child component lines carry the quantities (and the allocated price share, for returns) with `line_total` excluded from `items_total` to avoid double counting (CHECK via the action, totals sum the top-level lines only).

### 3. Module classes

- Catalog: `ProductType` enum. `CreateBundle`/`UpdateBundle` actions (components must be active simple products; a bundle cannot be a component). `BundleAvailability` service: available bundles = min over components of `floor(available_base / (qty × factor))`. The storefront resource for a bundle lists its components; `in_stock` = availability ≥ 1.
- `GenerateGradeSet` action: takes a `ProductFilterData` (e.g. grade + term + publisher), lists the matching active products' default units (qty 1 each), and creates a draft bundle the merchant reviews. Name suggested from the facet labels.
- Pricing: `BundlePricer` (mode fixed or sum of the components' resolved base prices − %). Group/promotion layers apply to the **bundle price** as to any SKU; customer prices may target the bundle SKU unit.
- Orders: `PlaceOrder`/`CheckoutValidator` explode a bundle line into components for the stock check and reservation (summed with any loose lines of the same SKU). Snapshot: one bundle line + child lines, with the price allocated to the children proportionally to their resolved prices (half-up, the remainder on the last child). Transitions and partial delivery operate on the child lines (delivered quantities per bundle, D93). Returns (Task 01) return child lines at their allocated price.
- Inventory: low-stock and inventory lists ignore bundle SKUs. Merchant reports show bundle sales and component consumption.
- Import/export: bundles are exported to a second sheet `bundles_v1`; import of bundles is not supported (D93).

### 4. Endpoints

`POST /api/dashboard/bundles`, `PUT /bundles/{product}`, `POST /bundles/generate` (filter → draft). Store product resource: `type: bundle`, `components[]`, `availability`. The cart adds by the bundle's `sku_unit_id` as usual.

### 5. Frontend

- Dashboard: a "باقة" product type in the product editor with a component picker (search by facet), quantities, pricing mode with a live sum/saving; a "إنشاء باقة صف دراسي" wizard from the facet selector.
- Storefront: bundle card with "وفّر …"; product page lists the components with stock; unavailable bundles show which component is missing.
- Order pages: the bundle line expandable to the components.

## Test list

- `Unit/Services/BundleAvailabilityTest`: min over components with units/factors; a component out of stock → 0.
- `Unit/Services/BundlePricerTest`: fixed vs percent-off-sum; group/promotion on the bundle price; rounding.
- `Unit/Actions/CreateBundleTest`: validation (no nested bundles, active components only), `GenerateGradeSetTest` from a filter.
- `Unit/Services/CheckoutValidatorTest`: a bundle + a loose copy of a component share the stock; the shortfall is reported on the bundle line.
- `Unit/Actions/CheckoutTest`: snapshots (bundle + children, allocation sums to the bundle total), reservations per component; `items_total` counts the bundle once.
- `Unit/Actions/TransitionOrderTest`: delivery deducts the components; partial delivery of bundles.
- `Unit/Actions/RecordReturnTest`: returning one component of a bundle at its allocated price.
- `Http/Store/ProductsTest`, `Http/Dashboard/Catalog/BundlesTest`.
- Frontend: component picker, availability message; e2e: buy a grade set.

## Slices

1. Docs + schema + `ProductType`.
2. Bundle CRUD, availability, pricing, generator.
3. Checkout/snapshots/transitions/returns.
4. Frontend.

## Definition of done

Green suites; curl: generate a grade set → buy it → stock of every component reserved then deducted → return one book; docs updated.

## Open decisions (proposed)

- **D93** Bundles: one level only (no bundle inside a bundle); fixed components with fixed quantities; no own stock (availability derived from components); the price is fixed or the components' sum minus a %; the order keeps the bundle line plus child lines with the price allocated proportionally, and returns and partial delivery work on the child lines. Bundles are exported but not imported.
