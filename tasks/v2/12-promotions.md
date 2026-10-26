# V2 Task 12 — Promotional pricing

Status: **done** (2026-10-27, commit: see log). Time-boxed promotions (`promotions`, `promotion_skus` materialised at every save, `order_items.promotion_id`): one % off the base price of the SKUs a product filter (the bulk-pricing filter) or an explicit list selects, live while scheduled/active and `starts_at ≤ now < ends_at`. `Pricing\Services\ActivePromotions::forSkus()` fills the `PricingContext` (through `PricingContextQuery::for($customer, $skuIds, ?$at)`, guests too), so the `PriceResolver` stays DB-free and applies D90: a negotiated price still wins, else the lowest of base/tier, group and promotion (`source = promotion`). Every price follows it: product list and page, price list and its Excel file, cart, checkout (re-read inside the checkout transaction), pre-orders and their conversion, the merchant's quote and order, order edits. Dashboard `/api/dashboard/promotions` (list, create, preview, show, PATCH, cancel; `pricing.manage`), `pricing:promotions-tick` every minute, `409 promotion_locked`; storefront `promotion { id, name, discount_percent, ends_at }` and `?on_offer=1`. Frontend: «العروض» page, «عرض ترويجي» on the products page's filter, the offer badge with «ينتهي خلال …» on cards, product page, cart and price list, the home «عروض» row. D90. Requires V2 Task 11. Read `CLAUDE.md`, `rules/*.md`, then this file.

## Delivered (deviations from the plan below)

- **Live is judged by the clock, not the status column:** `ActivePromotions` reads `status in (scheduled, active)` and the window at the moment of pricing, so prices change exactly at the boundary; the tick only keeps the stored status right for the dashboard lists (and the resource shows the status as of now). There is **no price cache** to bump (facets carry no prices, the price list is uncached), so the tick bumps nothing: `FacetCacheVersion` is a Catalog service Pricing may not use, and nothing needed it.
- **Times:** stored as plain `timestamp` (UTC, like every other column; the dev PostgreSQL session runs at +03, so `timestamptz` with Laravel's offset-less writes would have shifted). The API takes Cairo wall time without an offset (`datetime-local`), any ISO offset otherwise, and answers UTC.
- **Materialisation:** `promotion_skus` is written on every save of a draft or scheduled promotion (drafts too, so an explicit list survives and the list shows a count); a product created later joins only when the promotion is saved again (D90 d). A selection with no SKU is `422`. The explicit SKU list is API-only: the dashboard creates promotions from the products page filter (the same filter as `BulkPricingDialog`), and the «العروض» page edits name, %, window, schedules drafts and cancels; changing the target means a new promotion.
- **Context through the existing contract:** `CustomerPricing::for()` / `PricingContextQuery::for()` take the SKU ids about to be priced (and an optional moment); a guest gets a context only when one of them is on a promotion. Storefront controllers collect the ids with `StoreProductResource::skuIds()` (related products included) and `PriceListRowResource::skuIds()`; the price-list file prices each 500-row chunk with its own context.
- **Extra:** `order_items.promotion_id` (CHECK: set exactly when `price_source = promotion`) for Task 16's reports; `cancelled_at`, `created_by` on promotions; `POST /promotions` honours `Idempotency-Key`; `PriceResolver::promotionPrice()` for the preview; on a tie the earlier of normal/group/promotion is the source, and among promotions the higher % then the oldest wins.
- Tests: backend 5304 passed (+224: resolver precedence dataset, ActivePromotions boundaries in Cairo/UTC, highest %, cancelled/draft ignored, one query; actions with audit, rollback, empty filter, locks; tick command + schedule; context query; every pricing caller incl. checkout re-check after the offer ended; HTTP dashboard endpoints with validation, tenant isolation, cross-host session, idempotency, error envelope; storefront list/page/price list/file/cart/checkout; permission matrix rows; models, enums, data, resources). Frontend 873 unit tests (+30); e2e «BR-PR15 a promotion made from the products filter shows on the storefront and is charged at checkout» on mobile and desktop, run against a production build (`next build` + `next start`: the dev server refuses to start while it shows a Next.js upgrade notice). Full e2e: everything else green except the known `price-list.spec.ts` print snapshot (date line); `order-editing` and `import-export` each failed once on a timing flake and pass when re-run.
- Smoke against `php artisan serve`: a 10% promotion on «سلاح التلميذ علوم ثانية إعدادي» created to start 90 s later; the store showed 136.00 until 23:25:00 UTC (02:25 Cairo) and 122.40 with the badge from that second; the tick moved it to `active`; a new shopper's cart and checkout charged 122.40, snapshot `promotion` / `promotion_id 7`; cancelling brought 136.00 back; the smoke order was cancelled and queued messages drained offline through the log driver (no real WhatsApp sent).


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
