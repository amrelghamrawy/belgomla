# V2 Task 12 — Promotional pricing

Status: **planned**. Requires V2 Task 11. Read `CLAUDE.md`, `rules/*.md`, then this file.

## Goal

The merchant runs time-boxed offers: "15% off all الأضواء books until the end of the month". A promotion targets products by the same filter as bulk pricing (category, facets, search) or by an explicit SKU list, gives a % off the base price, and is live between its start and end. The storefront shows the offer and its end date.

Out of scope: coupons, buy-X-get-Y, spend thresholds, stacking several promotions (D90), broadcasting the offer (D70).

## Read before coding

| What      | Where |
| --------- | ----- |
| Rules     | BR-PR04, BR-PR10, BR-PR11 (bulk by filter), BR-PR14 (Task 11 precedence), BR-P12 (empty filter refused, D29) |
| Decisions | D27, D29, D60 (Cairo time), D89 |

## Left by earlier tasks

- `Catalog\Data\ProductFilterData` + `FilteredProductsQuery` (the bulk pricing target selection); `Pricing\Queries\BulkPricingTargetsQuery`.
- Task 11 `PricingContext` and the resolver precedence with a `source`.

## Deliverables

### 1. Docs

D90 into 06. New BR-PR15 (promotions). 07 §4 gets the tables. 08 gets the endpoints.

### 2. Schema

- `promotions`: `id, tenant_id, name, discount_percent numeric(5,2) CHECK 0 < x < 90, starts_at, ends_at timestamptz` (CHECK `ends_at > starts_at`), `filter jsonb null`, `status (draft|scheduled|active|ended|cancelled)`, `created_by`, timestamps.
- `promotion_skus`: `promotion_id, sku_id`, materialised when the promotion is scheduled (resolved from the filter or the explicit list), so price resolution is a lookup, not a filter query.

### 3. Module classes (Pricing)

- `Actions\CreatePromotion`, `UpdatePromotion` (draft/scheduled only), `CancelPromotion`. Scheduling materialises `promotion_skus` from the filter (an empty filter with no SKU list is refused, like D29). It audits.
- `Services\ActivePromotions::forSkus(list<skuId>, CarbonImmutable $at): array<skuId, PromotionPrice>`. It is added to the `PricingContext` (Task 11), so the resolver remains DB-free.
- `PriceResolver` (D90): the promotion price = base × (1 − %). The final price = the lowest of the normal/tier price, the group price and the promotion price. A negotiated customer price still wins outright. `source = promotion`.
- A SKU in two overlapping promotions → the higher % applies.
- `Console\PromotionsTickCommand` (`pricing:promotions-tick`, every minute) moves scheduled → active → ended and bumps `FacetCacheVersion`/price caches at the boundaries.
- Permission `pricing.manage`.

### 4. Endpoints

`GET|POST /api/dashboard/promotions`, `GET|PATCH /promotions/{id}`, `POST /promotions/{id}/cancel`, `POST /promotions/preview` (the count of SKUs + a sample). The store product, cart and price-list resources add `promotion{name, ends_at}` when the source is a promotion.

### 5. Frontend

- Dashboard "العروض" page: list, create/edit with the same filter builder as `BulkPricingDialog`, preview count, date-time pickers in Cairo time.
- Storefront: an offer badge with % and "ينتهي خلال …" on cards and the product page; an optional home section "عروض" listing active promotion products.

## Test list

- `Unit/Services/PriceResolverTest`: BR-PR15 promotion vs tier vs group vs customer price dataset (D90); overlapping promotions take the higher.
- `Unit/Services/ActivePromotionsTest`: boundaries at start and end (Cairo/UTC), cancelled ignored.
- `Unit/Actions/CreatePromotionTest`: materialised SKUs from the filter; empty filter refused; audit.
- `Console/PromotionsTickCommandTest` + `ScheduleTest`: status moves, cache bumped.
- `Unit/Services/CheckoutValidatorTest`: the price at checkout uses the promotion active **at checkout time** (re-checked under lock, BR-PR06), so an offer that ended between cart and checkout is not applied.
- `Http/Dashboard/Pricing/PromotionsTest`, `Http/Store/ProductsTest` (badge).
- Frontend: badge countdown, promotion form; e2e: create → storefront shows → checkout snapshot.

## Slices

1. Docs + schema + actions.
2. Resolver + context + tick command.
3. Endpoints + frontend.

## Definition of done

Green suites; curl: schedule a promotion starting in 1 minute, watch the price change on the store and in a checkout; docs updated.

## Open decisions (proposed)

- **D90** Promotions do not stack. A SKU gets the single lowest of the normal/tier price, its group price, and the best active promotion. A negotiated customer price (D89) is never undercut by a promotion. Promotions are a % off the base price only.
