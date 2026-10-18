# V2 Task 14 — Product variants (color / size)

Status: **planned**. Requires V2 Task 13. Read `CLAUDE.md`, `rules/*.md`, then this file.

## Goal

A product can have several SKUs that differ by option values, such as a pen in blue/black/red or a school bag in sizes S/M/L. Each variant SKU has its own code, stock, units and prices. The storefront shows one product with option selectors. This lifts the V1 invariant "exactly one SKU per product" (BR-P02, `07-domain-model.md` §3).

Out of scope: variant-specific images beyond one image per option value, and variants for books (D16: a final revision is a separate product, not a variant).

## Read before coding

| What      | Where |
| --------- | ----- |
| Rules     | BR-P02, BR-P03, BR-P07 (images), BR-P08 (custom fields), BR-P11 (duplicate), BR-PR02 (tiers never combined across SKUs), BR-MOQ02 |
| Tables    | `07-domain-model.md` §3 (line ~201 V1 invariant), §15 |
| Decisions | D16, D30, D33 (stationery colour field) |

## Left by earlier tasks

- The one-SKU convention (no DB constraint): `Product::sku()` HasOne `oldestOfMany`, `ProductData`/`SkuData` with a single `sku`, `Pricing\Queries\SkuPricingQuery::forProduct(Product): Sku`, resources reading `$product->sku`. Tests pin it: `tests/Unit/Models/ProductTest.php`, `tests/Unit/Actions/CreateProductTest.php`, `tests/Http/Dashboard/Catalog/ProductsTest.php`.
- Task 13: SKUs have `sku_units`. Task 06: import format v2 (one row per SKU unit).
- D33: stationery has a `color` custom field (a product-level select). It stays for products without variants; variant colour is a variant option.

## Deliverables

### 1. Docs

D92 into 06. BR-P02 is rewritten (1..N SKUs, variant options). New BR-P14 (variants). 07 §3: the invariant is removed and the new tables are added. 08: product payload/resource shapes. 02 note on facets.

### 2. Schema

- `product_options`: `id, tenant_id, product_id, name (e.g. "اللون"), sort_order`. At most 2 options per product (D92).
- `product_option_values`: `id, tenant_id, product_option_id, value, sort_order, image_id null FK product_images`.
- `sku_option_values`: `sku_id, product_option_value_id`. Unique per (sku, option); the combination is unique per product (enforced in the action).
- `skus.status` (`active|inactive`) so one variant can be hidden; `skus.sort_order`.

### 3. Module classes

- Catalog: `Product::skus()` becomes the API everywhere; `Product::sku()` is removed. Every caller is moved (grep `->sku\b`, `sku()`, `forProduct`). Models `ProductOption` and `ProductOptionValue`.
  - `ProductData` gets `options[]` + `skus[]` (each with `code`, `option_values`, `units[]`, `opening_stock`). A product without options has exactly one SKU (the V1 shape, still the default in the form).
  - Actions:
    - `CreateProduct`/`UpdateProduct` sync options and SKUs. A removed SKU that has orders is set inactive, never deleted (BR-P05).
    - `GenerateVariants` (support/service): the cartesian product of option values → SKU drafts with codes `{base}-{value-slug}`.
    - `DuplicateProduct` copies all variants (D30 naming per SKU code).
  - Storefront: `StoreProductResource.skus[]` + `options[]`. The card shows the price range ("من … إلى …") and is in stock if any variant is.
  - Facets: option values become facet-able per tenant option name (normalised, "اللون" across products). `FacetQuery` adds variant options next to custom fields.
  - Search matches every SKU code.
- Pricing: bulk pricing applies per SKU unit across variants. The price list shows one row per variant unit.
- Inventory: unchanged (per SKU). The inventory list shows the variant label.
- Orders: the snapshot `sku_name` = the variant label ("أزرق / M") — the column exists.
- Import/export: `variant_option_1/value_1/option_2/value_2` columns; rows sharing a `product_key` form one product (format v3).

### 4. Endpoints

Dashboard product create/update/show with `options` and `skus[]`. `PUT products/{product}/skus/{sku}/units/{unit}/pricing`. Store product detail `options` + `skus`. The cart is unchanged (`sku_unit_id`).

### 5. Frontend

- Product editor: a "خيارات المنتج" section (up to 2 options, values as chips) → a generated variants table (code, status, units/prices per row with "apply to all", stock).
- Storefront product page: option selectors (swatches for values with an image, buttons otherwise); unavailable combinations disabled; the price/stock/unit picker follow the chosen SKU; the URL keeps `?sku=`.
- Category page facets for options; cards show the price range.

## Test list

- `Unit/Actions/CreateProductTest`/`UpdateProductTest`: BR-P14 a product with options creates one SKU per combination; codes unique per tenant (BR-P03); a duplicate combination refused; removing a SKU with orders → inactive; no options = one SKU (V1 behaviour).
- `Unit/Services/GenerateVariantsTest`: cartesian product, code slugs, Arabic values.
- The three tests that pinned one SKU are rewritten to pin "no options ⇒ one SKU".
- `Unit/Queries/FacetQueryTest`: option facets and counts; `StoreProductListQuery` price range and in-stock any.
- `Unit/Actions/DuplicateProductTest`: all variants copied.
- `Unit/Jobs/ImportCatalogTest`: format v3 groups rows into one product.
- `Http/Store/ProductsTest`, `Http/Dashboard/Catalog/ProductsTest`.
- Frontend: selector logic (disabled combos), editor generation; e2e: buy two colours of one pen.

## Slices

1. Docs + schema.
2. Replace `Product::sku()` across modules (no behaviour change; all tests green).
3. Options/variants in actions, resources, facets.
4. Import/export v3.
5. Frontend.

## Definition of done

Green suites; curl: create a 3-colour × 2-size product, buy two variants, stock per variant; demo data still loads; docs updated.

## Open decisions (proposed)

- **D92** A product has at most two options (e.g. colour and size) with up to 20 values each, and every combination is its own SKU with its own code, stock, units and prices. Books keep D16 (revisions are separate products). Product-level custom fields stay product-level; option values are not custom fields.
