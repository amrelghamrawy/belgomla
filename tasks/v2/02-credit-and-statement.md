# V2 Task 02 — Credit terms (آجل) and customer account statement (كشف حساب)

Status: **done** (2026-10-19). Read `CLAUDE.md`, `rules/*.md`, then this file.

### Delivered

- Docs: D77, D78; D24 resolved; BR-C05..C08 in §I; settings list in §M; 07 customers/tenant_settings/orders.due_at/order_returns.refund_method/collections.method+customer_credit_id/customer_credits; 08 §8, §10 statement + credits, §3 checkout `details.credit`, §11 aging and overview.
- Deviations, recorded in the docs: the aging adds a `current` bucket (not due yet) next to 0–30/31–60/61–90/over 90; credits only come from returns (no manual credit endpoint), so voiding a credit voids the `credit` refund that created it; the return's `refund_method` column says how a refund went back; the old per-order statement shape (`StatementOrder`) is replaced by the ledger; checkout locks the customer row so two checkouts cannot both fit under the limit; the store statement needs a session only (a blocked customer still sees what they owe).
- Tests: backend 3235 (+117), frontend 659 (+18), e2e "credit limit blocks the next checkout until the merchant collects" on mobile and desktop.
- Smoke against `php artisan serve`: return kept as credit → credit applied to the next order → the ledger shows refund_to_credit / credit_created / credit_applied and closes at the outstanding figure → voiding the applied credit is 409.

## Goal

1. The merchant sets a **credit limit** and **payment terms** (days) per customer. Orders become due `terms` days after delivery, overdue orders are visible, and checkout refuses an order that would take the customer over the limit.
2. A real **account statement**: a dated ledger of everything that changed what the customer owes (orders delivered, payments, refunds, returns, credits), with a running balance, a date range, print, and the customer's own read-only copy in `/account`.
3. **Customer credit**: an over-collection created by a return (Task 01) can be kept as credit and applied to another order instead of refunded in cash.

Out of scope: interest/late fees, automatic payment reminders (Task 03 could carry them later), credit insurance, accounting export.

## Read before coding

| What      | Where |
| --------- | ----- |
| Rules     | BR-C01..C04, BR-CU05, BR-PR06 (checkout steps), D59, D24 |
| Tables    | `07-domain-model.md` §5 customers, §7 orders, §8 collections; Task 01's returns |
| API       | `08-api-contract.md` §8 dashboard customers, `GET /dashboard/customers/{id}/statement`, §3 checkout, §11 reports |
| Decisions | D24, D59, D60, D58 |

## Left by earlier tasks

- `Collections\Queries\CustomerStatementQuery` returns paginated non-cancelled orders plus a summary (`orders_count, total, collected, outstanding`). It is a list of orders, not a ledger. `StatementOrderResource` and `StatementResource` exist. Route: `GET customers/{customer}/statement` → `CustomerStatementController::show` (in `routes/api/dashboard/collections.php`).
- Task 01: `returned_total`, refund rows (`collections.kind`), net totals everywhere, and the `RefundRequired` flow where the merchant must confirm a cash refund.
- `customers` has no credit columns. `Orders\Services\CheckoutValidator` checks status/MOQ/stock/MOV and collects problems into `CheckoutFailed`.

## Deliverables

### 1. Docs

D77, D78 into 06. New BR-C05 (credit limit), BR-C06 (payment terms / due / overdue), BR-C07 (customer credit) and BR-C08 (statement) in 02. D24 is marked resolved. 07 §5/§8 get the new columns and tables. 08 gets the endpoints.

### 2. Schema

- `customers.credit_limit numeric(12,2) null CHECK >= 0` (null = no limit), `customers.payment_terms_days smallint null CHECK between 0 and 365` (null = due on delivery).
- `tenant_settings.default_payment_terms_days smallint null` and `default_credit_limit numeric(12,2) null`, applied to new customers.
- `orders.due_at timestamptz null`, set on delivery = `delivered_at + terms` (snapshot of the terms at that time).
- `customer_credits`: `id, tenant_id, customer_id, source_return_id FK order_returns null, amount numeric(12,2) CHECK > 0, remaining numeric(12,2) CHECK >= 0 and <= amount, note, created_by, voided_at, void_reason, voided_by`, timestamps.
- `collections.method` CHECK adds `'credit'`, plus `collections.customer_credit_id FK null` (required when method = credit).

### 3. Module classes

**Customers**: `CustomerData`/requests accept `credit_limit` and `payment_terms_days` (dashboard create/update), audited as `customer.credit_terms_changed`.

**Orders**

- `TransitionOrder`/`ApplyTransition`: on `delivered`, set `due_at` from the customer's terms.
- `CheckoutValidator`: new step after MOV. If `credit_limit` is set and exposure + new order total > limit, add `CheckoutProblem::creditLimitExceeded` (D77 defines exposure). The amounts come from Collections through a contract `Orders\Contracts\CustomerExposure` (null binding = no limit, rebound by Collections).
- Scope `Overdue` (delivered, `due_at < now`, net outstanding > 0).

**Collections**

- `CollectionMethod::Credit`.
- Actions:
  - `KeepAsCredit` is used by Task 01's `RecordReturn` when the merchant picks "credit" instead of "refund". The `RefundsPayments` contract gains `keepAsCredit(Order, amount)`, so the return's over-collection becomes a refund row + a `customer_credits` row with the same amount, and cash never moves.
  - `ApplyCustomerCredit::handle(Order, CustomerCredit, amount)` records a `credit` collection and lowers `remaining` (both locked).
  - `VoidCustomerCredit` is allowed only while nothing of it is applied.
- Queries:
  - `CustomerExposureQuery` (implements the contract): Σ net outstanding of delivered orders + Σ net totals of open orders − Σ remaining credit.
  - `CustomerLedgerQuery` (replaces the body of `CustomerStatementQuery`) is a `UNION ALL` of:
    - order delivered (debit `order_total`, at `delivered_at`),
    - return (credit `total`, at `returned_at`),
    - payment (credit),
    - refund (debit),
    - credit created (no balance effect, info line),
    - credit applied (no effect — the payment line is the effect).

    Voided rows are excluded. It is ordered by date, then by id, with a running balance (window function) and an opening balance before `from` (D60 Cairo days). It is paginated with the opening balance carried in `meta`.
  - `OverdueQuery` for the report and the overview.

**Reports**

- `OutstandingReportQuery` adds the aging buckets `0_30, 31_60, 61_90, over_90` by days past `due_at` (or past `delivered_at` when there are no terms), plus `overdue_amount`.
- Overview adds `overdue_total` and `overdue_customers`.

### 4. Endpoints

| Method | Path | Notes |
| ------ | ---- | ----- |
| GET  | `/api/dashboard/customers/{id}/statement?from&to` | ledger rows `{date, type, reference, debit, credit, balance}` + `meta.opening_balance`, `summary{…, credit_limit, available_credit, overdue}` |
| GET  | `/api/dashboard/customers/{id}/credits` | credits with remaining |
| POST | `/api/dashboard/orders/{order}/apply-credit` | `idempotent`; `{credit_id, amount}` |
| POST | `/api/dashboard/credits/{credit}/void` | `idempotent`; `reason` |
| GET  | `/api/store/account/statement?from&to` | the signed-in customer's own ledger (no internal notes) |

Checkout 422 `checkout_failed` gains the item reason `credit_limit_exceeded` at order level: `details.credit{limit, exposure, order_total}`.

### 5. Frontend

- Customer form: credit limit and payment terms fields (with the tenant defaults in settings → ordering).
- Customer page: the statement tab becomes the ledger with a date range, opening/closing balance and a print view (`@media print`, like the price list). Available credit and overdue badges.
- Order page: "خصم من رصيد العميل" when the customer has credit.
- Return dialog (Task 01): the refund choice becomes a radio "رد نقدي / رصيد للعميل".
- Reports: aging columns on outstanding; overview overdue tile.
- Storefront `/account/statement`: read-only ledger with print. Checkout shows the credit-limit failure with the figures (D78).

## Test list

- `Unit/Queries/CustomerLedgerQueryTest`: every row type; voided rows excluded; running balance; opening balance before `from` on Cairo days; final balance = outstanding (D59) when credits are 0.
- `Unit/Queries/CustomerExposureQueryTest`: delivered outstanding + open orders − credit (D77).
- `Unit/Services/CheckoutValidatorTest`: BR-C05 the limit boundary (equal allowed, over refused), null limit never refuses, combined with other problems.
- `Unit/Actions/TransitionOrderTest`: `due_at` from terms; null terms → due at delivery.
- `Unit/Actions/KeepAsCreditTest`, `ApplyCustomerCreditTest` (cannot exceed remaining or the order's remaining; concurrency on the same credit), `VoidCustomerCreditTest`.
- `Unit/Queries/ReportsQueriesTest`: aging buckets boundaries; overdue in the overview.
- `Http/Dashboard/Customers/StatementTest`, `CreditsTest`; `Http/Store/Account/StatementTest` (own only, other customer 404); `Http/Store/CheckoutTest` credit failure payload.
- `SecuritySweepTest`, Arch (Customers never reads Collections' models).
- Frontend: ledger table, print snapshot, checkout credit message; e2e: limit blocks checkout, a payment unblocks it.

## Slices

1. Docs.
2. Schema plus credit terms on customers, `due_at`, overdue.
3. Exposure plus the checkout limit.
4. Customer credits (keep-as-credit, apply, void).
5. Ledger query and statement endpoints (dashboard and store), reports aging.
6. Frontend.

## Definition of done

`composer test` and frontend checks green; curl: set a limit → checkout refused → collect → checkout passes; return with keep-as-credit → apply to the next order; the statement balances; docs updated.

## Open decisions (proposed)

- **D77** Credit exposure = net outstanding of delivered orders + net totals of open (pending…out_for_delivery) orders − remaining customer credit. Open orders count because their stock is already reserved for the customer.
- **D78** A checkout refused for the credit limit shows the customer their limit and current balance, and the merchant's phone/WhatsApp to arrange payment. There is no automatic merchant notification.
