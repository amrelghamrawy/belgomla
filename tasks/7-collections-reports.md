# Task 7 — Collections & Reports (+ Audit API)

Status: **not started**. Requires Tasks 1–6. Read `CLAUDE.md`, `rules/*.md`, then this file.

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

- D36: Week grouping start day for sales report (ISO Monday vs Egyptian Saturday). Default: Saturday.
- D37: Can a collection be recorded before the order is confirmed (deposit)? Default: yes, any status except cancelled (BR-C04).
