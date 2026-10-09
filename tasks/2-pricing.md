# Task 2 — Pricing module

Status: **done** (see the delivery notes at the end). Requires Task 1 merged. Read `CLAUDE.md`, `rules/*.md`, then this file.

## Goal

Quantity-tier pricing per SKU, the single `PriceResolver` used by cart/checkout later, cover-price percentage entry, bulk pricing by filter, and the dashboard pricing endpoints.

## Read before coding

| What        | Where                                                                          |
| ----------- | ------------------------------------------------------------------------------ |
| Rules       | `02-business-rules.md` §E (BR-PR01..PR11), BR-U04, BR-MOQ01/02, BR-PR09 (no tax) |
| Tables      | `07-domain-model.md` §4 `sku_price_tiers`, `skus` price columns, §12 checkout pseudo-code (resolver signature) |
| Endpoints   | `08-api-contract.md` §6 "Pricing" (4 endpoints) and the `sku.tiers` shape in §1 |
| Reference   | `app/Modules/Tenancy` (class shapes), `app/Modules/Catalog` (Task 1)           |

## Deliverables

### 1. Schema

`sku_price_tiers` per §4: `(tenant_id, sku_id, min_quantity ≥ 1, unit_price > 0, discount_percent nullable)`, unique `(sku_id, min_quantity)`, checks as DB constraints.

### 2. Module `app/Modules/Pricing`

- **Models**: `SkuPriceTier` (final, `BelongsToTenant`, decimal:2 casts, factory).
- **Services**:
  - `PriceResolver::resolve(Sku $sku, int $quantity): ResolvedPrice` — pure, no DB beyond the loaded tiers; `ResolvedPrice` is a `Data` value (`unitPrice`, `tierMinQuantity|null`, `discountPercent|null`, `coverPrice|null`, `lineTotal`). BR-PR04 exactly: highest `min_quantity ≤ qty`, else base price.
  - `PercentPricing::priceFromPercent(string $coverPrice, string $percent): string` — money math on strings via `bcmath`, never floats; rounding half-up to 2 decimals. Document the rounding rule in the class docblock and in `02-business-rules.md` BR-PR10.
- **Data**: `SkuPricingData` (`coverPrice?`, `basePrice|basePricePercent`, `moq`, `tiers: list<TierData>`), `TierData` (`minQuantity`, `unitPrice|discountPercent`), `BulkPricingData` (`filter` from Catalog's product filter data, `basePricePercent`, `tiers`, `moq?`).
- **Actions**: `UpdateSkuPricing` (replaces tiers atomically, validates no duplicate thresholds and prices > 0, audits price/tier/moq changes as separate audit actions `sku.price_changed`, `sku.tiers_changed`, `sku.moq_changed`), `RecomputeFromPercentages` (BR-PR10 explicit action after cover price change), `BulkApplyPricing` (BR-PR11: preview mode returns `{affected, skipped_no_cover_price}` without writing; apply mode writes in one transaction with one audit batch).
- **Queries**: `SkuPricingQuery` (sku + tiers for the dashboard), `BulkPricingTargetsQuery` (reuses Catalog's `FilteredProductsQuery::builder(ProductFilterData)` → SKUs, exactly as `BulkSetProductStatus` does; read the filter with the `ReadsProductFilter` concern and `filterPrefix() = 'filter.'`).
- **Exceptions**: `InvalidTiers` (422 `invalid_tiers` with `details.tiers[]`), `MissingCoverPrice` (422 `cover_price_required`).
- **Http**: `UpdateSkuPricingRequest`, `BulkPricingRequest`; `SkuPricingResource`; controllers `Dashboard/SkuPricingController@update`, `Dashboard/PricingRecomputeController@store`, `Dashboard/BulkPricingController@store` + `@preview`; routes in `routes/api/dashboard/pricing.php`.
- **Catalog integration**: `StoreProductResource` now embeds `tiers` and `discount_percent` from the resolver's data (read-only), `CreateProduct`/`UpdateProduct` accept `sku.tiers` by delegating to `UpdateSkuPricing` (no duplicated validation).

### 3. Tests

- Unit/Services/PriceResolverTest: dataset of boundaries — qty = tier.min − 1, = min, between tiers, above last, no tiers, MOQ irrelevant to price; money strings compare exactly (`"90.00"`).
- Unit/Services/PercentPricingTest: rounding half-up cases (`120.00` × 25% → `90.00`; `99.99` × 33.33%), zero/negative rejected.
- Unit/Actions: `UpdateSkuPricing` (duplicate threshold 422, audit rows per changed thing, nothing audited when unchanged), `BulkApplyPricing` (preview writes nothing; apply skips SKUs without cover price and reports them; one batch id), `RecomputeFromPercentages` (only SKUs with stored percentages change; audited).
- Http/Dashboard/Pricing: happy, validation dataset, cross-tenant 404 + untouched, guest 401 (add to SecuritySweep).
- Arch: Pricing may use Catalog models read-only? **No** — Catalog's `Sku` is Catalog's model; Pricing gets it through `Catalog\Queries` or receives the model as an argument from the controller which obtained it through Catalog. Add the boundary to `ModulesBoundariesTest`.

### 4. Definition of done

`composer test` green; curl: set tiers, fetch the store product and see `tiers`, run bulk preview then apply on `publisher = الأضواء`; docs updated; commit message with test delta and BR ids; this file marked done.

## Open questions

- D27: Rounding rule for percent pricing (half-up to piaster). Default: half-up.
- D28: Should tiers be allowed to be *higher* than base price (surcharge)? Default: no, reject with 422.

## Delivery notes (session 2026-10-09)

Commit: `5f90187`. `composer test` green: 981 tests (+171 over Task 1's 810), PHPStan max clean, type coverage 100%. Smoke-tested on `php artisan serve` with tenant `alnoor`: product created with `sku.tiers` (base and tier as %), `PUT …/pricing`, duplicate thresholds → `422 invalid_tiers`, store product shows `tiers` + `discount_percent`, bulk preview then apply on `publisher = الأضواء` (2 affected, 1 skipped without cover price, one audit batch), recompute, guest `401`. The dev tenant `alnoor` predates the vertical templates, so the smoke script created its `publisher` field through the API.

Rules covered by tests: BR-PR01, BR-PR02, BR-PR03, BR-PR04, BR-PR05, BR-PR07 (line total), BR-PR10, BR-PR11, BR-MOQ01, BR-U04/U05, BR-T03, BR-T04, BR-L, D27, D28, D34.

Deviations from the brief above (rules/02 or the contract won):

- `BulkPricingController@preview` is `BulkPricingPreviewController@store`: rules/02 §4 allows REST method names only.
- `PercentPricing::priceFromPercent()` is an instance method on a `final readonly` service (rules/02 service shape), plus `percentOff()` for the implied discount.
- Extra classes: `SkuPricePlanner` (turns entered prices into concrete, validated values; shared by update, bulk preview and recompute so preview fails exactly like apply), `InvalidPricingInput` (422 `validation_failed` for an empty bulk filter or a % price rounding to 0), `Pricing\Concerns\ReadsSkuPricing` (one set of price rules/parsing used by the pricing request **and** Catalog's product requests under `sku.`), `BulkPricingResultResource`, `App\Support\Money::compare()/times()`.
- Boundary exceptions, enforced in `tests/Arch/ModulesBoundariesTest.php`: Catalog's `Sku::tiers()` relation is the only Catalog reference to a Pricing model; Pricing references only Catalog's `Product`/`Sku` (handed to it by route binding or Catalog queries), `FilteredProductsQuery`, `ProductFilterData` and `ReadsProductFilter`. Catalog resources read prices through `PriceResolver` (a Pricing service) rather than touching tier models.
- Audit split (BR-L): a product update no longer lists prices/MOQ in `product.updated`; they are `sku.price_changed` / `sku.moq_changed` / `sku.tiers_changed`. `product.created` now includes `sku.tiers`. BR-PR11 said "one audit record per SKU"; it is one per kind of change per SKU, same batch — the rule text was updated.
- Catalog: `SkuData` now carries a `SkuPricingData` (`pricing`); `DuplicateProduct` copies tiers exactly as stored; `ProductDetailQuery` and the store queries eager-load `sku.tiers`; the dashboard product detail also shows `sku.tiers` (contract §6).
- The old product-create rule `cover_price required_with base_price_percent` was replaced by the uniform `422 cover_price_required` from the planner, so the pricing endpoint (where the cover price may come from the database) and the product endpoints behave the same.
