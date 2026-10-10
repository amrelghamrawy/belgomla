# Task 7 — Collections & Reports (+ Audit API)

Status: **done** (see the delivery notes at the end). Requires Tasks 1–6. Read `CLAUDE.md`, `rules/*.md`, then this file.

## Goal

Cash collection records with void, derived collection status on orders, the "deliver and collect" shortcut, customer statement and outstanding balances, the four V1 reports, and the dashboard audit log endpoint.

## Read before coding

| What      | Where                                                                               |
| --------- | ----------------------------------------------------------------------------------- |
| Rules     | `02-business-rules.md` §I (BR-C01..C04), BR-O03, §L audit                           |
| Tables    | `07-domain-model.md` §8 collections, §10 audit indexes                               |
| Endpoints | `08-api-contract.md` §10 (4), §11 reports (4), §12 audit (1)                          |
| Decisions | D5 (reports), D7, D24 (no credit terms in V1)                                        |

## Left by Task 5

- Rebind `App\Modules\Customers\Contracts\OutstandingBalances` (bound to `NullOutstandingBalances` in `CustomersServiceProvider`) so the dashboard customer list and page show the real `outstanding_amount`.

## Left by Task 6

- `Orders\Contracts\CollectsPayments::collectRemaining(Order $order, ?int $actorId): void` is called by `TransitionOrder` inside its transaction (order row already locked) when delivering with `collect_full_amount`. It is bound to `Orders\Services\NullCollectsPayments` in `OrdersServiceProvider`; rebind it to the Collections implementation (a binding in a provider registered after `OrdersServiceProvider`, or replace the entry there).
- `orders.collected_amount` (default 0) and `collection_status` (`Orders\Enums\CollectionStatus`) exist; `CancelOrder` already refuses `collected_amount > 0` with `409 has_collections`.
- The dashboard order detail renders `collections: []` (`DashboardOrderResource`); fill it.
- The Orders arch rules (`tests/Arch/ModulesBoundariesTest.php`) forbid other modules from using `App\Modules\Orders`; Collections will need its own exception there (Orders models as typed arguments, `CollectionStatus`, the contract).
- `lang/` does not exist yet, so validation messages render as keys (`validation.in`); worth adding `lang/ar/validation.php` before the UI tasks.

## Deliverables

### 1. Schema

`collections` per §8 (`amount > 0`, `method` enum cash, `voided_at/void_reason/voided_by`), partial index `(order_id) where voided_at is null`.

### 2. Module `app/Modules/Collections`

- **Enums**: `CollectionMethod` (cash).
- **Models**: `Collection` (name clash with `Illuminate\Support\Collection` — name the model `CashCollection`, table `collections`), factory.
- **Services**: `CollectionBalance` (recompute `collected_amount` + `collection_status` on an order under lock; `Σ non-voided ≤ order_total`).
- **Actions**: `RecordCollection` (implements `Orders\Contracts\CollectsPayments`; rejects on cancelled orders and over-collection `422 exceeds_balance`; audit `collection.recorded`), `VoidCollection` (reason, audit `collection.voided`).
- **Queries**: `CollectionsQuery` (`?from=&to=&customer_id=&order=`), `CustomerStatementQuery` (orders with totals, collected, outstanding), `OutstandingByCustomerQuery` (feeds Customers list `outstanding_amount` through a `Customers\Contracts\OutstandingBalances` binding).
- **Http**: `RecordCollectionRequest`, `VoidCollectionRequest`; `CollectionResource`, `StatementResource`; controllers `Dashboard/CollectionController@index`, `Dashboard/OrderCollectionController@store`, `Dashboard/CollectionVoidController@store`, `Dashboard/CustomerStatementController@show`; routes `routes/api/dashboard/collections.php`.

### 3. Module `app/Modules/Reports` (read-only, Queries + Resources only)

- `OverviewQuery` (counts per status, today's orders, stale pending, outstanding total, low-stock count), `SalesQuery` (`group=day|week|month`, delivered orders by `delivered_at`), `OutstandingReportQuery`, `LowStockReportQuery` (reuses Inventory's `LowStockQuery`).
- Controllers `Dashboard/Reports/*Controller@show`; routes `routes/api/dashboard/reports.php`. Arch: Reports contains no Actions and writes nothing.

### 4. Audit API

`Dashboard/AuditLogController@index` in the Audit module with `AuditLogsQuery` (`?entity_type=&entity_id=&actor_id=&from=&to=&batch_id=`), paginated `AuditLogResource`; route in `routes/api/dashboard/audit.php`.

### 5. Tests

- Unit/Services/CollectionBalanceTest: derived status matrix (0 / partial / full / over → 422), void recomputes.
- Unit/Actions: record on cancelled order 409; over-collection 422; void audit.
- Orders integration: `TransitionOrder` with `collect_full_amount` now creates the collection in the same transaction (test in Orders' test file, extended).
- Reports: each query with a seeded dataset and frozen time; week grouping boundaries (Saturday start? decide: ISO weeks, document).
- Http matrix for 9 endpoints; audit filter by batch id returns the bulk action rows.

### 6. Definition of done

`composer test` green; curl: deliver + collect full, partial collection, void, statement, overview; docs (`08` if shapes changed, `06` for D36); commit; mark done.

## Open questions

- D58 (was "D36" here; D36/D37 are inventory/storefront decisions): week start of the sales report. Default: Saturday.
- Deposits (was "D37"): no decision needed, BR-C04 already allows any status except cancelled.
- Added while building: D59 (outstanding = delivered orders only) and D60 (business days in Africa/Cairo), both confirmed with the product owner before coding.

## Delivery notes (session 2026-10-10)

Commit: `0c2f33a`.

`composer test` is green with 2658 tests (2615 passed + 43 arch tests flagged deprecated by PHP 8.5's `setAccessible`), +233 over Task 6's 2425. PHPStan max is clean and type coverage is 100%.

What differs from the plan above, and why:
- `CollectionBalance` is not a Collections service. Recomputing the balance writes `orders`, so per rules/02 §2 it is an Orders **action**, `ApplyCollectedAmount`. Collections sums the non-voided amounts (`CollectedAmountQuery`) and hands the result to it. The status derivation lives on the enum: `CollectionStatus::for($collected, $total)`.
- `RecordCollection` keeps a single `handle()` (rules/02 §3). `CollectsPayments` is implemented by `Services\RemainingBalanceCollector`, which records whatever is left. It records nothing when a deposit already covered the order, and it refreshes the order the transition answers with.
- The order detail's `collections` come through a new Orders contract, `OrderCollections`, which returns `list<JsonResource>`. It is null-bound in `OrdersServiceProvider` and filled by `Services\OrderCollectionRecords`. The four dashboard order controllers pass it to `DashboardOrderResource`.
- `OrderCollectionController@store` answers the **collection** (`201`), not the order detail: Collections cannot use Orders' Http resources. The collection's `order { … }` carries the new balance and status, so the UI does not need a second call.
- `StatementResource` is the statement head (`customer`, `summary`) sent next to the paginated `StatementOrderResource` rows.
- Reports also has a `Data` folder (`SalesReportData`). Arch rules: Reports contains only `Data`, `Enums`, `Http` and `Queries`, and uses no writing actions, services or the `DB` facade. The low-stock report reuses Inventory's `LowStockQuery::builder()` unchanged. The `threshold` param from 08 §11 was dropped, because D36 fixes the threshold.
- New Support: `Money::subtract`, `BusinessCalendar` (Cairo days → UTC bounds, D60), and `config('app.business_timezone')`.
- `collections.recorded_by` is nullable (a system actor); 07 §8 updated. `CashCollection` exposes the user ids only, because Tenancy models stay inside Tenancy.
- Cross-host tests on routes with a bound id: route binding (tenant-scoped) runs before `session.tenant`, so an id that only exists in the session's own store is a 404 on another host. The tests therefore use an id that exists on the other host, which must be `401`.
- Not done: `lang/ar/validation.php` (left over from Task 6) is still missing. It is better done with the frontend foundation (Task 8), where the messages are shown. **Done in Task 8** (`backend/lang/ar/validation.php`, `APP_LOCALE=ar`).
- Follow-up: the orders list (Task 6) still filters `from`/`to` by UTC days; align it with D60 when the dashboard UI uses it.

Manual checks (`php artisan serve`, curl with `--resolve` and the SPA origin, owner session):
- **Deliver and collect**: order #3 (confirmed, 300.00) moved to `delivered` with `collect_full_amount`. The answer was `collected_amount 300.00`, `collected`, and one collection in `collections`.
- **Partial collection**: order #1 (delivered, 1530.00) took 500.00 with an Arabic note and answered `201` with `partially_collected`. Then:
  - 1030.01 more → `422 exceeds_balance` with `remaining 1030.00`;
  - a collection on the cancelled order #2 → `409 order_cancelled`.
- **Statement**: customer 4 showed both orders with balances 0.00 and 1030.00, and the summary had `outstanding 1030.00`. The customer list showed the same 1030.00.
- **Void**: voiding the 500.00 collection set order #1 back to `unpaid`. A second void → `409 already_voided`. The collections list showed it as voided.
- **Reports**:
  - The overview was correct, with `today` counted on the Cairo date.
  - In `sales?group=week`, order #3 was delivered at 22:03 UTC, which is the next day in Cairo, so it landed in the week starting Saturday 2026-10-10.
  - The outstanding and low-stock reports answered `200`.
- **Audit**: `audit?entity_type=collections` listed `collection.recorded` ×2 and `collection.voided` with the owner as actor. A guest on the reports → `401`.

Rules covered by tests: BR-C01, BR-C02, BR-C03, BR-C04, BR-O03, BR-L, BR-INV07, BR-T03, BR-T04, BR-CU02, Tech §6, D5, D7, D24, D36, D55, D58, D59, D60.
