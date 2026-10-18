# V2 Task 09 — Order editing and partial fulfilment

Status: **planned**. Requires V2 Tasks 03, 05, 08. Read `CLAUDE.md`, `rules/*.md`, then this file.

## Goal

1. **Editing**: before preparation starts, the merchant can change an order's lines (add a SKU, remove a line, change a quantity) instead of cancelling and asking the customer to re-order (BR-O06 today).
2. **Partial fulfilment**: when delivering, the merchant records what was actually delivered per line when it is less than ordered (a book went out of print, the customer refused some). The rest is released, and the order is charged for what was delivered.

Out of scope: splitting an order into several shipments or a back-order for the remainder (D86), the customer editing their own order.

## Read before coding

| What      | Where |
| --------- | ----- |
| Rules     | BR-O01, BR-O05, BR-O06, BR-O08, BR-PR04..PR07, BR-MOQ01, BR-INV03..INV05, BR-C03, BR-R* (Task 01) |
| Tables    | `07-domain-model.md` §7 `order_items` (CHECK `quantity_base = quantity × conversion_factor`), §6 movements |
| Decisions | D7, D74, D85 |

## Left by earlier tasks

- `UpdateDeliveryAddress` is the edit-guard pattern (`OrderStatus::allowsAddressEdit()`, `AddressLocked`, audit with old/new).
- `ApplyTransition` deducts every item's `quantity_base` on `delivered` through `StockLedger::deduct` and writes `sale` movements.
- `PriceResolver::resolve(Sku, qty)` and `OrderSnapshots::item(CheckoutLine)`. `CheckoutValidator` validates lines against stock. Task 07's `PlaceOrder` builds lines from `{skuId, quantity}`.
- Task 08: `allowsPriceChange()`, list vs charged unit price, manual discounts that must survive an edit.

## Deliverables

### 1. Docs

D86, D87 into 06. BR-O06 is rewritten (editable while pending/confirmed; partial delivery). BR-O12 is new (edit rules) and BR-O13 (partial delivery). 07 §7 gets the new columns and `order_item_changes`. 08 gets the endpoints.

### 2. Schema

- `order_items.delivered_quantity int null` (null until delivered; CHECK `0 <= delivered_quantity <= quantity`), `order_items.removed_at timestamptz null` (a removed line is kept for history and excluded from totals).
- `order_item_changes`: `id, tenant_id, order_id, order_item_id null, change (added|removed|quantity|delivered_short), old_quantity, new_quantity, unit_price_before, unit_price_after, actor_id, created_at`.

### 3. Module classes (Orders)

- `OrderStatus::allowsLineEdit()`: pending or confirmed.
- `Data\OrderEditData` (`lines[{orderItemId|null, skuId, quantity}]` = the full desired line list, `note`).
- `Actions\EditOrderLines::handle(Order, OrderEditData, actorId)`, owning its transaction:
  1. Lock the order and check the guard (`409 order_locked`).
  2. Compute the diff against the live lines.
  3. Lock all SKUs involved in `sku_id` order.
  4. Release the removed/decreased quantities and reserve the added/increased ones. `CheckoutValidator` runs on the new lines with the current stock + this order's own reservation counted as available.
  5. Re-price every changed line through `PriceResolver` (tiers may change with the quantity; unchanged lines keep their snapshot price).
  6. Keep the manual line discounts (Task 08) on lines that survive. Recompute the order discount amount when its type is percent.
  7. Recompute the totals. Refuse when the total < collected (`409 below_collected`).
  8. Write `order_item_changes`, history note "تعديل الطلب", audit `order.lines_changed`, and notify the customer (template `edited`, D87).

  MOV: re-checked. Going below the MOV is allowed for the merchant but warned in the response (`meta.below_mov`).
- `TransitionData` gains `deliveredQuantities: array<orderItemId, int>|null`. `ApplyTransition` on `delivered`: for each line, deduct `delivered_quantity × factor` and release the remainder; set `delivered_quantity`; recompute `items_total`/`order_total` from the delivered quantities (the order discount, if a fixed amount, is capped at the new items total). It writes `delivered_short` changes. Without the field, everything was delivered (V1 behaviour).
- Task 01 `ReturnableQuantities` uses `delivered_quantity`.
- Permission `orders.edit` (owner, manager, sales) in the Task 05 map. The delivered quantities follow `orders.manage`.

### 4. Endpoints

- `PUT /api/dashboard/orders/{order}/lines` (`idempotent`, `permission:orders.edit`). It returns the order + `meta.below_mov`.
- `POST /api/dashboard/orders/{order}/transition` accepts `delivered_quantities` when `to = delivered`.
- `DashboardOrderResource` adds `changes[]` and the line `delivered_quantity`. The store resource shows the delivered quantity and the removed lines struck through.

### 5. Frontend

- Order page: "تعديل الأصناف" (pending/confirmed) reuses the Task 07 line editor with the quote endpoint. It shows the diff before saving.
- Transition to delivered: the dialog lists the lines with a "تم تسليم" quantity stepper defaulting to ordered (collapsed under "تسليم جزئي؟").
- A history section shows the changes.
- Storefront order detail: changed/removed/short lines marked.

## Test list

- `Unit/Actions/EditOrderLinesTest`: add / remove / increase / decrease each adjust the reservations exactly; tiers re-priced on a quantity change; unchanged lines keep their price after a catalog price change; manual discounts survive; stock shortfall → 422 like checkout with nothing changed; locked after preparing; below collected refused; MOV warning; changes + audit + customer message.
- `Unit/Actions/EditOrderLinesConcurrencyTest`: an edit and a checkout race for the last units.
- `Unit/Actions/TransitionOrderTest`: BR-O13 partial delivery deducts the delivered, releases the rest, totals recomputed, collection status re-derived, `sale` movements match; full delivery unchanged without the field; delivered > ordered → 422.
- `Unit/Services/ReturnableQuantitiesTest`: uses the delivered quantity.
- `Http/Dashboard/Orders/EditLinesTest`, `TransitionTest` (partial).
- Frontend: diff view, partial delivery dialog; e2e: edit then deliver short then return.

## Slices

1. Docs + schema.
2. `EditOrderLines` with its tests.
3. Partial delivery in the transition.
4. Endpoints + frontend.

## Definition of done

Green suites; curl: order 3 lines → edit (+1 SKU, −1 line, qty change) → confirm → deliver short on one line → stock, reservations and totals verified at each step; docs updated.

## Open decisions (proposed)

- **D86** Partial delivery drops the undelivered remainder (released to stock). There is no automatic back-order or second shipment; the merchant can place a new order for it (Task 07).
- **D87** The customer is notified by WhatsApp when the merchant edits the lines of their order (template `edited`, on by default) and sees the changes on the order page, because the total they will pay changed.
