# V2 Task 08 — Manual order and item discounts

Status: **planned**. Requires V2 Tasks 01, 05, 07. Read `CLAUDE.md`, `rules/*.md`, then this file.

## Goal

The merchant can lower the price on an order: a discount on one line (amount per unit or %), and/or a discount on the whole order (amount or %). It is allowed while the order is not yet being prepared, given by users with the permission, and audited. Collections, outstanding, returns, statements and reports all use the discounted (net) amounts.

Out of scope: coupons, automatic promotions (Task 12), customer-specific price lists (Task 11), discounts the customer chooses at checkout.

## Read before coding

| What      | Where |
| --------- | ----- |
| Rules     | BR-PR07 (price snapshots), BR-PR08 (no manual discount in V1 → V2), BR-O05, BR-O06, BR-O08 (total), BR-C03, BR-R* (Task 01) |
| Tables    | `07-domain-model.md` §7 `orders`, `order_items` (`discount_percent` is **display only**: the tier margin off the cover price) |
| Decisions | D27 (rounding half-up to the piaster), D59, D74 |

## Left by earlier tasks

- `order_items`: `unit_price`, `discount_percent` (display), `line_total = unit_price × quantity` (`Money::times`). `orders.items_total`, `order_total`.
- Task 01: `returned_total`, `NetTotal`, returns priced at the snapshot `unit_price` (D74). Task 07: merchant-placed orders (the discount can be given at creation).
- `Pricing\Services\PercentPricing::priceFromPercent` (bcmath, half-up, D27).

## Deliverables

### 1. Docs

D85 into 06. BR-PR08 is rewritten (manual discounts V2). BR-O08: `order_total = items_total − order_discount`. BR-R01 return pricing at the discounted line price, prorated (D85). 07 §7 new columns. 08 gets the endpoint.

### 2. Schema

- `order_items.list_unit_price numeric(12,2)` (the price before the manual discount = today's `unit_price`; backfilled from `unit_price`), `manual_discount_type varchar(8) null` (`amount|percent`), `manual_discount_value numeric(12,2) null`. `unit_price` stays the price actually charged per unit, so the existing `line_total = unit_price × quantity` invariant and every reader keep working.
- `orders.discount_type varchar(8) null`, `discount_value numeric(12,2) null`, `discount_amount numeric(12,2) default 0 CHECK >= 0 and <= items_total`, `discount_reason varchar(300) null`.
- `order_total = items_total − discount_amount`. A CHECK holds it.

### 3. Module classes

- `Pricing\Services\DiscountCalculator` (or Support if pure): `unitAfter(listPrice, type, value)` → never below 0.01 (`422 discount_too_large`); `orderAmount(itemsTotal, type, value)`.
- `Orders\Data\DiscountData` (`items[{orderItemId, type|null, value}]`, `order{type|null, value, reason}`).
- `Orders\Actions\ApplyOrderDiscount::handle(Order, DiscountData, actorId)`:
  - locks the order;
  - allowed while `pending` or `confirmed` (`OrderStatus::allowsPriceChange()`), else `409 order_locked`;
  - recomputes the lines, `items_total`, `discount_amount` and `order_total`;
  - refuses if the new total < collected (`409 below_collected`);
  - re-derives the collection status via `ApplyCollectedAmount`;
  - writes history note "خصم";
  - audit `order.discount_changed` with old/new per line;
  - notifies the customer (Task 03, new template key `discount`, off by default).
- `PlaceOrderForCustomer` (Task 07) accepts the same discount block at creation.
- Returns (Task 01): the return line price = the line's `unit_price` (after the item discount) × (1 − order discount share), where share = `discount_amount / items_total`. It is rounded half-up per line, and the last return of an order absorbs the rounding difference so returns never exceed `order_total` (D85).
- Permission `orders.discount` (owner, manager; sales optional per D85) in the Task 05 map, plus `tenant_settings.max_discount_percent_sales` null = sales cannot discount.

### 4. Endpoints

`PUT /api/dashboard/orders/{order}/discount` (`idempotent`, `permission:orders.discount`). `DashboardOrderResource` lines show `list_unit_price`, `unit_price`, the manual discount, and the order shows `discount{type, value, amount, reason}`. The store resource shows the line price and the order discount (no reason).

### 5. Frontend

- Order page (pending/confirmed): a "خصم" button opens a dialog with a per-line discount (amount/% switch) and an order discount, with live totals and the reason required for an order discount.
- New-order page (Task 07): the same controls.
- Storefront order detail and printed invoice: crossed list price, the discount line in the totals.

## Test list

- `Unit/Services/DiscountCalculatorTest`: amount/percent, rounding half-up (D27), refuses ≤ 0 result, the order discount capped at the items total.
- `Unit/Actions/ApplyOrderDiscountTest`: line + order discount totals; removing a discount restores the list price; locked after preparing; below collected refused; collection status re-derived; audit old/new; permission checked in the HTTP test.
- `Unit/Actions/RecordReturnTest` (extend): BR-R pricing with the order discount prorated; full return of all lines = exactly `order_total` (rounding absorbed, D85).
- `Unit/Queries/*`: outstanding, statement and sales use the discounted totals.
- `Http/Dashboard/Orders/DiscountTest`: validation, permission matrix, idempotency, sales cap setting.
- Frontend: discount dialog math (shared with `lib/dashboard/price-math.ts`); e2e: discount then collect full.

## Slices

1. Docs + schema (with backfill) + calculator.
2. `ApplyOrderDiscount`, creation-time discounts, return proration.
3. Endpoint, resources, frontend.

## Definition of done

Green suites; curl: order → item 10% + order 50 EGP → collect → return one line → amounts match by hand; docs updated.

## Open decisions (proposed)

- **D85** Discounts: allowed while the order is pending or confirmed; never below 0.01 per unit; an order discount needs a reason. Who may give them: owner and manager always; sales only up to `max_discount_percent_sales` per line when the owner sets it (default none). Returns are priced at the discounted line price minus the order-discount share, with rounding absorbed by the last return.
