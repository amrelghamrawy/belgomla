# Task 6 — Orders & Checkout module (+ in-app Notifications)

Status: **not started**. Requires Tasks 1–5. Read `CLAUDE.md`, `rules/*.md`, then this file.

## Goal

The checkout transaction, order lifecycle with guards, customer/merchant cancellation, per-tenant order numbers, snapshots, status history, delivery address edit, merchant in-app notifications, and the `ReservationHolders` implementation for Inventory.

## Read before coding

| What      | Where                                                                                                   |
| --------- | ------------------------------------------------------------------------------------------------------- |
| Rules     | `02-business-rules.md` BR-PR05..PR07, §H (BR-O01..O10), BR-INV03..INV05, BR-INV07, §K BR-N02            |
| Tables    | `07-domain-model.md` §7 (orders, counters, items, history), §9 notifications, §11 state machines, §12 transaction |
| Tech      | `04-technical-constraints.md` §10–§11                                                                   |
| Endpoints | `08-api-contract.md` §3 checkout + orders (4), §9 dashboard orders (5), §12 notifications (2)            |
| Decisions | D7 (cancel with collections), D9 (address edit), D10 (stale 24h)                                        |

## Left by Task 5

- Rebind `App\Modules\Customers\Contracts\RecentOrders` (bound to `NullRecentOrders` in `CustomersServiceProvider`) so the dashboard customer page shows `recent_orders`.
- Checkout reads the cart through `Customers\Queries\CartQuery` / `Actions\RefreshCart` and re-checks with `Services\OrderableQuantity` and `CartPricer` under lock (BR-PR06). `EnsureCustomerActive` (`customer.active`) already guards customer routes.

## Deliverables

### 1. Schema

`tenant_order_counters`, `orders` (status + collection_status enums, snapshots, timestamps), `order_items` (all snapshot columns incl. `tier_min_quantity`, `cover_price`, `discount_percent`), `order_status_history`, `notifications` (own table, not Laravel's default). `order_returns` is **not** created (D20).

### 2. Module `app/Modules/Orders`

- **Enums**: `OrderStatus` with `isTerminal()`, `canTransitionTo(OrderStatus)` (forward-only along the path, skipping allowed, cancelled from any non-terminal), `label()`; `CollectionStatus` (unpaid, partially_collected, collected) — defined here, computed by Task 7; `CancelledBy` (customer, tenant_user).
- **Models**: `Order`, `OrderItem`, `OrderStatusHistory`, `TenantOrderCounter`; factories with states per status and with items.
- **Data**: `CheckoutData` (`addressId`, `customerNote`), `TransitionData` (`to`, `note`, `collectFullAmount`), `CancelData` (`reason`), `DeliveryAddressData`.
- **Services**: `OrderNumbers` (lock counter row, next number), `CheckoutValidator` (steps 1–7 of BR-PR06 producing a list of `CheckoutProblem` items or the priced lines; reuses `PriceResolver`, `StockLedger::lock`, settings MOV), `OrderSnapshots` (builds item/address snapshots), `OrderStateMachine` (pure transition rules + side-effect plan).
- **Actions**: `Checkout` (owns the transaction in exactly the §12 order; throws `CheckoutFailed` 422 with `items[]` and `details.mov`; clears cart; writes history, notification, audit), `TransitionOrder` (guards, timestamps, on `delivered` → `StockLedger::deduct` + `sale` movements; `collect_full_amount` delegates to Task 7's `RecordCollection` through a contract `Orders\Contracts\CollectsPayments` with a null binding until Task 7), `CancelOrder` (customer: pending only; merchant: any non-terminal; `collected_amount > 0` → 409 `has_collections`; releases reservations; reason required), `UpdateDeliveryAddress` (before out_for_delivery; audited), `MarkNotificationsRead`.
- **Inventory integration**: implement `Inventory\Contracts\ReservationHolders` here (`OrdersHoldingSku`) and bind it in `OrdersServiceProvider`, replacing Task 3's `NullReservationHolders` binding in `InventoryServiceProvider`. Add the FK `inventory_movements.order_id → orders.id` in the orders migration (Task 3 left it a plain column because `orders` did not exist). `StockLedger::reserve/release/deduct` throw `NotInTransaction` outside a transaction and re-lock the row themselves; call `StockLedger::lock()` on all SKUs of the order first so the locks are taken in `sku_id` order.
- **Queries**: `CustomerOrdersQuery`, `OrderDetailQuery` (items, history, collections placeholder), `DashboardOrdersQuery` (`?status=&collection_status=&customer_id=&from=&to=&q=&stale=1` using `stale_pending_hours`), `NotificationsQuery`.
- **Policies**: `OrderPolicy` (`view`: own customer; `cancel`: customer only when pending; merchant abilities through guard, not policy). Register in the provider.
- **Notifications**: `NewOrder`, `OrderCancelledByCustomer` as `final class ... extends Notification` (database channel) **or** rows in our own `notifications` table through a `Notify` service — pick the own-table approach (schema §9) and document it.
- **Exceptions**: `CheckoutFailed`, `InvalidOrderTransition` (409 `invalid_transition`, `details.from/to`), `OrderHasCollections` (409), `CannotCancel` (409), `AddressLocked` (409).
- **Http**: `CheckoutRequest` (idempotency header handled by `IdempotencyKey` middleware in `app/Support/Http/Middleware`, Redis-backed, 24 h), `TransitionOrderRequest`, `CancelOrderRequest`, `UpdateDeliveryAddressRequest`; Resources `OrderResource` (store), `DashboardOrderResource` (adds customer + collections + history), `OrderItemResource`, `NotificationResource`; controllers `Store/CheckoutController@store`, `Store/OrderController@index/@show`, `Store/OrderCancelController@store`, `Dashboard/OrderController@index/@show`, `Dashboard/OrderTransitionController@store`, `Dashboard/OrderCancelController@store`, `Dashboard/OrderDeliveryAddressController@update`, `Dashboard/NotificationController@index`, `Dashboard/NotificationReadController@store`; routes `routes/api/store/orders.php`, `routes/api/dashboard/orders.php`.

### 3. Tests

- Unit/Services/CheckoutValidatorTest: datasets for every failure reason and the exact `items[]` payload; MOV boundary equal/under.
- Unit/Actions/CheckoutTest: success creates order+items+history+notification+audit, reserves stock, clears cart, assigns sequential numbers per tenant (two tenants both get #1); failure rolls everything back (no order, no reservation, cart intact); **concurrency**: two checkouts for the last units → one `insufficient_stock`.
- Unit/Enums/OrderStatusTest: full transition matrix as a dataset.
- Unit/Actions/TransitionOrderTest: forward/skip/backward/terminal; delivered deducts stock and writes `sale` movement; `collect_full_amount` calls the contract.
- Unit/Actions/CancelOrderTest: customer vs merchant rules, reservation released, collections guard, reason required.
- Http: all 11 endpoints with the matrix, idempotency (same key twice → same order, no duplicate), cross-tenant order number 404, policy 403 for another customer's order.
- Arch: Orders may use Inventory only via `StockLedger`/contracts, Pricing via `PriceResolver`, Customers via queries/actions, never their models directly except as typed arguments.

### 4. Definition of done

`composer test` green; curl: full flow register → cart → checkout → merchant confirm → deliver (stock deducted) → customer sees history; stale filter; docs; commit; mark done.

## Open questions

- D34: Idempotency key scope (per customer per tenant). Default as stated.
- D35: Should the merchant be able to create an order on behalf of a customer in V1? Default: no (V2).
