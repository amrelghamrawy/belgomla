# V2 Task 01 — Returns (المرتجع)

Status: **planned**. Requires V2 Task 00. Read `CLAUDE.md`, `rules/*.md`, then this file.

## Goal

A merchant records goods a customer brings back after delivery. The goods go back into stock, and the order's value drops by the returned amount, which lowers what the customer owes. If the customer has already paid more than the new value, the difference is a cash refund. This is the first V2 increment (D20), and the end-of-season return is standard in the school-book trade.

Out of scope: returns before delivery (that is cancelling or, from Task 09, editing); customer-initiated return requests from the storefront; keeping a refund as customer credit instead of cash (Task 02 adds that option).

## Read before coding

| What      | Where                                                                                           |
| --------- | ----------------------------------------------------------------------------------------------- |
| Rules     | BR-INV06 (movement types, line 502 on returns), BR-O05, BR-O06, BR-O08, BR-C02..C04, D59         |
| Tables    | `07-domain-model.md` §6 `inventory_movements`, §7 orders/order_items, `order_returns` (reserved), §8 collections, §15 |
| API       | `08-api-contract.md` §9 dashboard orders, §10 collections, §11 reports                           |
| Decisions | D20, D59 (outstanding), D60 (Cairo days), D7 (cancel with collections)                          |

## Left by V1

- `Inventory\Enums\MovementType::Return` exists ("مرتجع"), documented as never written in V1. The `inventory_movements` type CHECK already allows `'return'`.
- `StockLedger` has `reserve/release/deduct/adjust/openingStock`. Nothing increases `on_hand` for an order. `record()` is private.
- `orders.order_total` = `items_total` (BR-O08). `collected_amount` and `collection_status` are set only by `Orders\Actions\ApplyCollectedAmount`, which refuses an amount above `order_total`.
- `Collections\Queries\OutstandingByCustomerQuery` sums `order_total − collected_amount` over delivered orders. It feeds the customer list, statement summary, outstanding report and overview.
- `Reports\Queries\SalesQuery` sums `order_total` of delivered orders.

## Deliverables

### 1. Docs (slice 1)

- D74–D76 into `06-open-decisions.md`.
- New BR-R01..R06 in `02-business-rules.md` §H (or a new §H2 "Returns"). Update BR-INV06 (`return` is now written), BR-O08 (net total), BR-C03 (status uses the net total) and D59 (outstanding net of returns).
- `07-domain-model.md` §7: the `order_returns` and `order_return_items` tables, plus `orders.returned_total`. §8: `collections.kind`. §15: drop the Returns row.
- `08-api-contract.md`: the returns endpoints (below).

### 2. Schema

- `order_returns`: `id, tenant_id, order_id FK, number int` (per order: 1, 2, …), `total numeric(12,2) CHECK > 0`, `refund_amount numeric(12,2) default 0 CHECK >= 0`, `reason varchar(300) not null`, `returned_at timestamptz`, `recorded_by FK tenant_users`, `voided_at, void_reason, voided_by`, timestamps. Unique `(order_id, number)`.
- `order_return_items`: `id, tenant_id, order_return_id FK, order_item_id FK, quantity int CHECK >= 1, quantity_base int, unit_price numeric(12,2), line_total numeric(12,2)`. CHECK `quantity_base = quantity * conversion_factor` is enforced in the action (the factor lives on the order item).
- `orders.returned_total numeric(12,2) default 0 CHECK >= 0` and CHECK `returned_total <= order_total`.
- `collections.kind varchar(10) default 'payment'`, CHECK `in ('payment','refund')`. A refund row has a positive `amount` and lowers the collected figure.

### 3. Module classes

**Inventory**

- `StockLedger::restock(Sku $sku, int $quantityBase, int $orderId, ?int $actorId): void`. Must run inside a transaction and re-locks the row. It does `on_hand += qty` and writes a positive `return` movement with `order_id`.
- `StockLedger::unrestock(...)` for voids. It refuses with `InsufficientStock` when `available < qty` (D76) and writes a negative `return` movement.
- Arch: the StockLedger stays the only writer.

**Orders**

- No status enum: a return is either live or voided (`voided_at`).
- Models `OrderReturn` and `OrderReturnItem` (BelongsToTenant, factories). `Order::returns()`.
- Data `ReturnData` (`reason`, `returnedAt`, `lines: list<ReturnLineData{orderItemId, quantity}>`, `refund: bool`).
- Services:
  - `ReturnableQuantities::for(Order): array<int orderItemId, int>`: delivered quantity minus live returned quantity.
  - `NetTotal` (support): `order_total − returned_total`.
- Actions (each owns its transaction):
  - `RecordReturn::handle(Order, ReturnData, ?actorId)`:
    1. Lock the order.
    2. Refuse unless the status is `delivered` (`ReturnNotAllowed::status`).
    3. Validate every line ≤ returnable (`ReturnExceedsDelivered`).
    4. Lock the SKUs in `sku_id` order, then `restock` each line.
    5. Price the lines at the snapshot `unit_price` (D74).
    6. Add to `returned_total`.
    7. If `collected_amount > net total`, the difference is the refund. It requires `refund = true` from the merchant, otherwise `422 refund_required` with the amount. On refund, call Collections' `RecordRefund` through a contract (`Orders\Contracts\RefundsPayments`, null binding, rebound by Collections like `CollectsPayments`).
    8. `ApplyCollectedAmount` with the net total.
    9. Write status history (no status change, note "مرتجع #n"). No merchant notification, because the merchant recorded it.
    10. Audit `order.returned`.
  - `VoidReturn::handle(OrderReturn, reason, ?actorId)`: the mirror image. It unrestocks (D76), reduces `returned_total`, and fails `409 return_has_refund` when the return's refund is not voided first (the same order of operations as D7).
- `ApplyCollectedAmount` and `CollectionStatus::for(collected, total)` take the **net** total. Every caller passes `NetTotal`.
- Exceptions: `ReturnNotAllowed` (409), `ReturnExceedsDelivered` (422, `details.items[]`), `RefundRequired` (422, `details.refund_amount`), `ReturnHasRefund` (409).
- Policy: `OrderReturnPolicy::void` (merchant only, not already voided), registered in `OrdersServiceProvider`.

**Collections**

- `CollectionKind` enum (`payment`, `refund`).
- Action `RecordRefund::handle(Order, string $amount, ?actorId, ?note)` creates a `refund` row and audits `collection.refunded`. It is the `RefundsPayments` implementation.
- `CollectedAmountQuery::forOrder` = Σ payments − Σ refunds (live rows).
- `RecordCollection`: remaining = net total − collected.
- `VoidCollection` works for both kinds.
- `OutstandingByCustomerQuery` and `CustomerStatementQuery`: `order_total − returned_total − collected_amount`.

**Reports**

- `SalesQuery`: net sales = delivered `order_total` by `delivered_at` − live returns by `returned_at` (Cairo day, D60). Buckets show `gross`, `returns`, `net`.
- `OverviewQuery`: `today.total` is net, and there is a new `today.returns`.

### 4. Endpoints

| Method | Path | Notes |
| ------ | ---- | ----- |
| GET  | `/api/dashboard/orders/{order}/returns` | the order's returns plus `returnable[]` per item |
| POST | `/api/dashboard/orders/{order}/returns` | `idempotent`; body `reason, returned_at?, refund?, items[{order_item_id, quantity}]` |
| POST | `/api/dashboard/returns/{return}/void` | `idempotent`; `reason` |
| GET  | `/api/dashboard/returns` | list: `?from&to&customer_id&q`, paginated |

`DashboardOrderResource` adds `returned_total`, `net_total` and `returns[]`. The store `OrderResource` adds `returned_total` and `returns[]` (read-only). Collection resources add `kind`. OpenAPI and types are updated.

### 5. Frontend

- Order page (`components/dashboard/orders/…`): a "تسجيل مرتجع" button on delivered orders opens a dialog with one row per item (ordered, already returned, a stepper capped at returnable), the reason, and the date. When the API answers `refund_required`, the dialog shows the amount and a confirm checkbox "رد المبلغ نقدًا". It has a returns section with void.
- Collections list: refund rows are shown with a minus sign and a "رد" badge.
- New nav entry "المرتجعات" (orders group) → returns list.
- Reports: the sales chart shows net, with returns as a second series.
- Storefront `/account/orders/[number]`: a returns block (read-only).

## Test list (stubs first, rules/01 §6)

- `Unit/Services/StockLedgerTest`: restock raises on_hand and writes a `return` movement with order_id; unrestock refuses below available (D76); both throw `NotInTransaction` outside a transaction.
- `Unit/Services/ReturnableQuantitiesTest`: delivered minus live returns; a voided return gives back its quantity.
- `Unit/Actions/RecordReturnTest`:
  - BR-R01 only delivered orders (dataset of the other statuses → 409)
  - BR-R02 a quantity above returnable → 422 with items
  - restocks every line in base units
  - priced at the snapshot unit_price even after a price change (D74)
  - `returned_total` and the net collection status update (collected → partially or the reverse)
  - over-collected without refund → 422 `refund_required` and nothing is saved
  - with refund → a refund row, collected lowered, audit
  - two returns get numbers 1 and 2
  - audit row
- `Unit/Actions/RecordReturnConcurrencyTest`: two parallel returns of the last returnable unit → one fails.
- `Unit/Actions/VoidReturnTest`: reverses stock and total; blocked by a live refund; blocked when the stock was sold again (D76); a second void → 409.
- `Unit/Actions/RecordRefundTest`, plus `CollectedAmountQuery`: payments − refunds.
- `Unit/Queries/CollectionsQueriesTest`: outstanding net of returns (update the D59 datasets).
- `Unit/Queries/ReportsQueriesTest`: sales buckets gross/returns/net on Cairo days; overview today returns.
- `Http/Dashboard/Orders/ReturnsTest`: every endpoint, validation, idempotent replay, cross-tenant 404, the voided return shown.
- `Http/Store/Orders/OrdersTest`: returns visible to the owner of the order only.
- `SecuritySweepTest`: the new routes.
- `Arch`: returns models are used only in Orders (and Reports for reading); Collections reaches returns only through the `RefundsPayments` contract.
- Frontend: `ReturnDialog.test.tsx` (caps, refund confirm), the returns list; e2e: deliver → return 2 → stock back, outstanding lowered.

## Slices

1. Docs and decisions.
2. Inventory `restock`/`unrestock` plus tests.
3. Schema, models, `RecordReturn`/`VoidReturn`, Collections refunds, net-total plumbing.
4. Endpoints, resources, reports, OpenAPI.
5. Dashboard and storefront UI, e2e.

## Definition of done

`composer test` green; frontend checks green; curl: deliver an order with collections → return part → refund → void the refund → void the return; stock, totals and outstanding are right at each step; docs updated; board updated with the commit.

## Open decisions (proposed)

- **D74** Return price: the snapshot `unit_price` of the order item (a tier price stays the tier price even if fewer units come back). No re-pricing by tier.
- **D75** Return window: none. Any delivered order can take returns. A merchant who wants a cut-off refuses at the counter.
- **D76** Voiding a return whose stock is no longer available (sold again): refused `409 insufficient_stock`. The merchant adjusts stock first.
