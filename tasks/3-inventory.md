# Task 3 — Inventory module

Status: **not started**. Requires Tasks 1–2. Read `CLAUDE.md`, `rules/*.md`, then this file.

## Goal

Per-SKU stock in base units with reservation support, movements ledger, merchant adjustments with the BR-INV08 guard, opening stock on product creation, low-stock listing, and the `StockLedger` service that Orders (Task 6) will call inside its transaction.

## Read before coding

| What      | Where                                                                                      |
| --------- | ------------------------------------------------------------------------------------------ |
| Rules     | `02-business-rules.md` §G (BR-INV01..INV08), BR-U03, BR-P04 (coming_soon has no stock check) |
| Tables    | `07-domain-model.md` §5 (`sku_inventory`, `inventory_movements`), §12 lock order           |
| Tech      | `04-technical-constraints.md` §11 (row locks, atomicity)                                   |
| Endpoints | `08-api-contract.md` §7 (4 endpoints) and the `opening_stock` field in §6 product create   |

## Deliverables

### 1. Schema

`sku_inventory` (`sku_id` PK, `tenant_id`, `on_hand ≥ 0`, `reserved ≥ 0`, check `reserved ≤ on_hand`), `inventory_movements` per §5 with `type` enum including `return` (reserved, never written in V1).

### 2. Module `app/Modules/Inventory`

- **Enums**: `MovementType` (opening, adjustment, sale, return), `MovementActorType` reuse `Audit\Enums\ActorType`.
- **Models**: `SkuInventory`, `InventoryMovement` (append-only like AuditLog), factories.
- **Services**: `StockLedger` — the only writer of `sku_inventory`:
  - `openingStock(Sku, int)`, `adjust(Sku, int $newOnHand, string $reason, actor)` → throws `BelowReserved` (422 `inventory_below_reserved`, `details.orders[]` filled by a callback/contract so Inventory does not depend on Orders: define `Inventory\Contracts\ReservationHolders` with `ordersHolding(Sku): list<{number,status,quantity_base}>`; Orders binds the implementation in Task 6; a null implementation returns `[]` until then).
  - `reserve(Sku, int $base)`, `release(Sku, int $base)`, `deduct(Sku, int $base)` — all require the caller to have locked the row (`lockForUpdate`) in its transaction; assert `DB::transactionLevel() > 0` and throw `RuntimeException` otherwise.
  - `lock(list<int> $skuIds): Collection<SkuInventory>` — `FOR UPDATE` ordered by `sku_id` (deadlock avoidance).
- **Actions**: `AdjustStock` (uses ledger + audit `stock.adjusted`), `BulkAdjustStock` (applied/skipped report, one audit batch), `RecordOpeningStock` (called by Catalog's `CreateProduct` through this action, not the ledger directly).
- **Queries**: `InventoryListQuery` (`?low_stock=1&q=` with `available` computed), `MovementsQuery`, `LowStockQuery` (`available ≤ moq`, used by Reports later).
- **Exceptions**: `BelowReserved`, `InsufficientStock` (422 `insufficient_stock`, used by Orders), `NotInTransaction` (plain RuntimeException).
- **Http**: `AdjustStockRequest`, `BulkAdjustStockRequest`; `InventoryRowResource` (on_hand, reserved, available, min_allowed = reserved), `MovementResource`; controllers `Dashboard/InventoryController@index/@show`, `Dashboard/StockAdjustmentController@store`, `Dashboard/BulkStockAdjustmentController@store`, `Dashboard/MovementsController@index`; `routes/api/dashboard/inventory.php`.
- **Catalog integration**: `CreateProduct` accepts `opening_stock` and calls `RecordOpeningStock`; `StoreProductResource` adds `available` and switches `in_stock` from status-based (Task 1) to `available > 0` (coming_soon → `in_stock=false`, `available` omitted). Add the dashboard product list filter `low_stock=1` to `ListDashboardProductsRequest` / `DashboardProductListQuery` (deferred from Task 1).

### 3. Tests

- Unit/Services/StockLedgerTest: every method; `reserved ≤ on_hand` DB constraint; calling outside a transaction throws; **concurrency**: two parallel `reserve` attempts on the last units using two DB connections (`DB::connection('pgsql')` + a second named connection in `config/database.php` for tests) → exactly one succeeds; document the approach in the test.
- Unit/Actions/AdjustStockTest: BR-INV08 message lists holding orders (use a fake `ReservationHolders`), audit row, movement row with `on_hand_after`.
- Http: 4 endpoints with the standard matrix; the 422 payload shape from `08-api-contract.md` §7 exactly.
- Arch: Inventory models only written through `StockLedger` (`not->toBeUsedIn` outside `Inventory\Services` and `Inventory\Actions`).

### 4. Definition of done

`composer test` green; curl: create product with opening stock, adjust down, attempt below reserved (seed a reservation via tinker), bulk adjust; docs; commit; this file marked done.

## Open questions

- D29: Allow negative adjustments to zero when reserved = 0? Yes (default). Allow deleting a SKU with stock? No (products are archived, never deleted).
