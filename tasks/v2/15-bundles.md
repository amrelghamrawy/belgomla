# V2 Task 15 — Bundles and full-grade sets

Status: **planned**. Requires V2 Tasks 01, 13, 14. Read `CLAUDE.md`, `rules/*.md`, then this file.

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
