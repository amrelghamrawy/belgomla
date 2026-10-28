# V2 Task 14 — Product variants (color / size)

Status: **done** (2026-10-29, commit: see log). A product has up to two options with up to twenty values (`product_options`, `product_option_values`, one swatch image per value) and one SKU per combination it sells (`sku_option_values`, `skus.status` active|inactive, `skus.sort_order`, the label in `skus.name`). The product payload keeps `sku` for a product without options and takes `options[]` + `skus[]` otherwise; `SaveProductSkus` syncs options, values and SKUs (matched by id, code, then combination; a SKU left out is deleted, or set inactive when it has history), `VariantGenerator` makes the `{base}-{slug}` codes and labels, `DuplicateProduct` copies every variant. `Product::sku()` is gone: `skus()` / `activeSkus()` / `options()` everywhere. Storefront `skus[]`, `options[]`, `price_range`, `sku` = first variant in stock; option facets `o:<folded name>` and the `f[o:…]` filter; price list per variant unit; cart lines carry `variant`; inactive variants refused in cart, checkout and pre-orders; order `sku_name` = the label; inventory rows carry `variant`, low stock skips inactive variants. Catalog file format v3 (`product_key`, `variant_option_1/value_1/option_2/value_2`, `sku_status`). Frontend: «خيارات المنتج» and the variants table in the product editor, option selectors with swatches and `?sku=` on the product page, price range and «اختر المواصفات» on cards, option facets, variant labels in cart, orders, price list and inventory. Demo: a highlighter in three colours (ST-007-1..3). D92. Requires V2 Task 13. Read `CLAUDE.md`, `rules/*.md`, then this file.

## Delivered (deviations from the plan below)

- **Payload:** the plain `sku` object stays for a product without options (the editor, the import and every existing caller keep it); `options` + `skus` is the variant shape (sending both, or `sku` with `options`, is 422). Errors of the variant shape are keyed `skus.{i}.…` / `options.{i}.…`; a variant code taken elsewhere is `409 duplicate_code` with `details.skus.{i}.code`. Option values are referenced by their text in `skus[].option_values` (ids do not exist yet on a create); names and values compare folded (D32).
- **Removal (D92 c):** a SKU left out is **deleted** when nothing points at it (order lines, stock movements, negotiated prices, promotions: `SkuHistoryQuery`), otherwise set inactive and kept after the others. Options and values left out are deleted; the kept SKU keeps its label in `skus.name`. A combination may be left out (not every colour in every size).
- **Pricing route:** no nested `products/{p}/skus/{sku}/units/{unit}/pricing`: the Task 13 route `products/{p}/units/{skuUnit}/pricing` already addresses a unit of any variant; recompute and bulk pricing cover every variant.
- **`VariantGenerator` is a service** (the naming rule: no verb), tested in `Unit/Services/VariantGeneratorTest`. An Arabic value is slugged by its position in its option (codes stay in the SKU code alphabet); the frontend mirrors the rule.
- **Storefront `sku`** is kept as the variant standing for the product (first in stock, else first active), next to `skus[]`; cards of products with several variants link to the page («اختر المواصفات») instead of quick-adding. The pre-order cap stays the product's, in that variant's default unit.
- **Cart lines carry `variant`** (not planned; the cart showed two identical names otherwise).
- **Import/export v3 (D95):** rows group by `product_key` (empty = the SKU code), `sku_status` column, an import never removes a variant, option or value; counts are products (= SKUs for v1/v2 files). `CatalogRowMapper` re-checks the request's after-hooks per row (duplicate unit, defaults, combinations, one active) because a Validator built from the rules does not run them.
- **Facets:** one facet per folded option name across products (`type: option`, key `o:<folded name>`, id the folded value), after the custom fields; label = the spelling most products use.
- Not in this task (D92 i): variant images beyond one swatch per value, stock or prices per option value. A bulk status update of SKUs outside the product form would not bump the facet cache (nothing does that today).
- Tests: backend 5596 passed (+152: migration and rollback; models `ProductOption`, `ProductOptionValue`, `Sku` status/option values, `Product` ordering; `VariantGenerator`; `SaveProductSkus` (delete vs inactive, match by code and combination, opening stock, option sync, second option, removing options); `SkuHistoryQuery`; `ProductReferences` ids of other products and variant code conflicts; `ProductData` variant shape; snapshot; duplicate; dashboard `VariantsTest` (3×2 create, validation datasets, update removing a size, tenant isolation, swatch, list, duplicate); storefront page/list/price range/facets/filter/search/price sort/on offer; price list and its file; cart, refresh, checkout validator and checkout with two colours; inventory and low stock; facet cache; import v3 grouping, update keeping variants, errors on their row, round trip with an inactive variant; demo seeder). The three tests that pinned one SKU now pin «no options ⇒ one SKU». Frontend 942 unit tests (+51); e2e «BR-P14 a shopper buys two colours of one pen» on mobile and desktop. Full e2e on a production build (`next build` + `next start`): all 50 green.
- Smoke against `php artisan serve` (gateway off, no worker): a 3-colour × 2-size bag created through the dashboard API (six SKUs, stock 20–45, M at 15.00); the storefront page listed both options, six variants, `price_range` 12.00–15.00; the filters showed `o:اللون` and `o:المقاس`; a merchant order of 2 × «أزرق / S» and 3 × «أسود / M» snapshotted those labels and reserved 2 and 3 on those variants only; the order was cancelled (reserved back to 0) and the product set inactive. No outbound message was created; the e2e run's queued OTPs were drained offline through the log driver.

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
