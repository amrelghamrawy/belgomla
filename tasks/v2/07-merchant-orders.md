# V2 Task 07 — Merchant places an order on behalf of a customer

Status: **planned**. Requires V2 Tasks 03, 05. Read `CLAUDE.md`, `rules/*.md`, then this file.

## Goal

Many wholesale orders arrive by phone or WhatsApp. The merchant (or a sales user) creates the order in the dashboard for an existing customer. It goes through the same pricing, MOQ, stock, MOV and credit checks as a storefront checkout, and then lives exactly like any other order.

Out of scope: orders for walk-in customers without an account (create the customer first; `CreateCustomerByMerchant` exists), price overrides (Task 08), pre-orders (Task 10).

## Read before coding

| What      | Where |
| --------- | ----- |
| Rules     | BR-O02, BR-PR06 (checkout steps), BR-O04, BR-O09, BR-O10, BR-CU03, BR-C05 (Task 02) |
| Tables    | `07-domain-model.md` §7, §12 (checkout transaction order) |
| API       | `08-api-contract.md` §3 checkout, §9 dashboard orders |
| Decisions | D56, D55 (idempotency), D57, D77 |

## Left by earlier tasks

- `Orders\Actions\Checkout::handle(Customer, CheckoutData)` reads the lines from `Customers\Queries\CartQuery::linesOf($customer)` and clears the cart. Everything else (lock, `CheckoutValidator`, `OrderNumbers`, `OrderSnapshots`, reserve, history, `NotifyMerchants`, audit) is reusable.
- `CheckoutValidator::validate(lines, stock)` works on a list of lines and is independent of the cart.
- Task 02: the credit limit in the validator. Task 03: `NotifyCustomerOfStatus`. Task 05: permissions.

## Deliverables

### 1. Docs

D84 into 06; D56 marked resolved. BR-O02 adds "the merchant may place an order for a customer". BR-O11 is new (merchant-placed orders: same checks, actor, notify). 07 §7 gets `orders.placed_by_type`/`placed_by_id`. 08 gets the endpoint.

### 2. Schema

`orders.placed_by_type varchar(12) default 'customer'` CHECK `in ('customer','tenant_user')`, and `orders.placed_by_id bigint null`.

### 3. Module classes (Orders)

- Refactor: extract `Services\OrderPlacement` (or a step action `PlaceOrder`) taking `(Customer, CustomerAddress, list<CartLineInput> lines, PlacedBy actor, ?note, bool skipMov)`. It holds everything `Checkout` does after loading the lines. `Checkout` becomes: load cart lines → `PlaceOrder` → clear cart. Its behaviour and tests stay identical.
- `Data\MerchantOrderData` (`customerId, addressId, lines[{skuId, quantity}], note, notifyCustomer, overrideMov`).
- `Actions\PlaceOrderForCustomer::handle(MerchantOrderData, int $actorId)`: loads the customer (it must be `active`; blocked → `409 customer_blocked`) and the address (one of theirs), builds the lines through Catalog's `CartSkuQuery`, then calls `PlaceOrder`. Audit `order.created` has `placed_by`. There is no `new_order` merchant notification (the merchant made it). The customer is notified with a new template `placed` (Task 03 template map gains `placed`, default "تم تسجيل طلبك رقم …") when `notifyCustomer`.
- `overrideMov` requires the `orders.override_mov` permission (owner and manager only, D84).
- Request `StoreMerchantOrderRequest`, controller `Dashboard/OrderController@store`, route `POST /api/dashboard/orders` (`idempotent`, `permission:orders.create`).
- New permissions `orders.create` (owner, manager, sales) and `orders.override_mov` (owner, manager) in the Task 05 map.
- Support for the picker: `POST /api/dashboard/orders/quote` returns priced lines + problems without saving (`CheckoutValidator` dry run), so the form shows tiers and stock live.

### 4. Frontend

- `/dashboard/orders/new`: customer search (name/phone) → address select (with an "add address" inline using the existing customer edit) → product picker (search, SKU, stock, tier hint) → line list with the quote → note → "إرسال رسالة للعميل" toggle → submit. On success it goes to the order page.
- Orders list: a "طلب جديد" button (with permission); a "أنشأه التاجر" badge.
- The customer page has a "طلب جديد لهذا العميل" shortcut.

## Test list

- `Unit/Actions/CheckoutTest`: unchanged and green after the refactor (regression guard; run it before and after).
- `Unit/Actions/PlaceOrderForCustomerTest`:
  - BR-O11 creates an order with the snapshots, reservations and history actor tenant_user, `placed_by`
  - same failures as checkout (dataset: inactive product, below MOQ, stock, credit limit)
  - MOV enforced unless override with permission (D84)
  - blocked customer → 409
  - another customer's address → 422
  - customer notified only when asked
  - order numbers shared with storefront orders
- `Unit/Actions/PlaceOrderConcurrencyTest`: a merchant order and a storefront checkout for the last units → one wins.
- `Http/Dashboard/Orders/MerchantOrderTest`: validation, idempotent replay, permission matrix rows, quote endpoint saves nothing.
- `SecuritySweepTest`.
- Frontend: the new-order form (quote errors per line, override toggle visible by permission); e2e: phone order end to end.

## Slices

1. Docs + refactor of `Checkout` into `PlaceOrder` (no behaviour change, all tests green).
2. Schema, `PlaceOrderForCustomer`, quote, endpoints.
3. Frontend.

## Definition of done

Green suites; curl: quote → place for a customer → customer sees it in `/account/orders` with the message logged; docs updated.

## Open decisions (proposed)

- **D84** A merchant-placed order obeys the store's MOV like a storefront order. The owner or a manager may tick "تجاوز الحد الأدنى" for one order, and the override is audited. MOQ, stock and the credit limit are never overridable here.
