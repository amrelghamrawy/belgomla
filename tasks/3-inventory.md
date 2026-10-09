# Task 3 — Inventory module

Status: **done** (see the delivery notes at the end). Requires Tasks 1–2. Read `CLAUDE.md`, `rules/*.md`, then this file.

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

- D35 (written as "D29" here before; that id was already taken by bulk status): Allow negative adjustments to zero when reserved = 0? Yes (default). Allow deleting a SKU with stock? No (products are archived, never deleted).

## Delivery notes (session 2026-10-09)

`composer test` green: 1129 tests (+148 over Task 2's 981), PHPStan max clean, type coverage 100%. Smoke-tested on `php artisan serve` with tenant `alnoor`:
- created a product with `opening_stock: 100` (opening movement, `sku.inventory`);
- listed `inventory?q=` and fetched `inventory/{sku}`;
- adjusted down to 60;
- seeded a 40-unit reservation through `StockLedger::reserve` in tinker;
- adjusting to 10 gave `422 inventory_below_reserved` with `min_allowed: 40`;
- bulk adjust applied our SKU and reported kotob's SKU as `not_found` (untouched), and a bulk below reserved was skipped with `below_reserved`;
- movements came back newest first;
- the store product showed `available: 5, in_stock: false` (5 pieces < one carton of 20, D37);
- `products?low_stock=1` listed it;
- a guest got `401`.

The migration backfilled an empty stock row for every existing SKU.

Rules covered by tests: BR-INV01, BR-INV02, BR-INV03, BR-INV04, BR-INV05, BR-INV06, BR-INV08, BR-U03, BR-U05, BR-P04, BR-L, BR-T03, BR-T04, Tech §11, D5, D30, D32, D35, D36, D37.

Decisions applied (06-open-decisions.md): D35 (to-zero allowed, same value is a no-op, `unchanged` in bulk), D36 (low stock = available ≤ MOQ × conversion factor, base units; the contract's "available ≤ moq" compared base units with selling units), D37 (storefront `in_stock` = at least one selling unit available).

Deviations from the brief above (rules/02 or the contract won):

- An extra `InventoryRowQuery` loads one SKU's row, so controllers stay query-free, and `GET inventory/{sku}` exists (contract §7 note).
- `Data/StockAdjustmentData` and `Data/BulkStockAdjustmentData` exist because rules/02 requires a Data class for 3+ inputs. The ledger and actions take an `?int $actorId`; the movement's `actor_type` is `tenant_user` when given, `system` otherwise.
- `AdjustStock::handle()` returns whether On Hand changed (not the movement), so `BulkAdjustStock` never touches the Inventory models.
- `StockLedger` details:
  - `reserve`/`release`/`deduct` re-lock the row themselves through `lock()`. That is free when the caller already holds it, and safe when it forgot.
  - `lock()` creates missing empty rows, so SKUs from factories or old data without a row still work. Every read treats a missing row as zero.
  - `openingStock`/`adjust` open their own (nesting) transaction.
- `NotInTransaction` lives in `Exceptions/` but extends `RuntimeException` (500, not an API error). The arch rule "exceptions extend ApiException" ignores it explicitly.
- The bulk-adjust body is a bare array per the contract. The request validates it as `items.*`, so errors read `items.0.sku_id`. The result is `{ applied: <count>, skipped[], batch_id }`, and a summary audit `inventory.bulk_adjusted` shares the batch.
- `inventory_movements.order_id` has no FK yet; Task 6 adds it with `orders` (noted there). The DB also checks the movement type, that an adjustment has a reason, and that `on_hand_after ≥ 0`.
- Concurrency test (`tests/Unit/Services/StockLedgerConcurrencyTest.php`):
  - It uses two extra connections, `pgsql_session_a`/`pgsql_session_b` in `config/database.php`, through the `onSession()` helper in `tests/Pest.php`.
  - The race is interleaved in one process with `lock_timeout` (no `pcntl` on Windows): the second session times out on the row lock (`55P03`) while the first holds it, then finds nothing left.
  - Its fixtures are committed under their own tenant and deleted after each test.
  - The "outside a transaction" ledger tests use the same fresh session, since RefreshDatabase keeps the default connection inside a transaction.
- Catalog:
  - `Sku::inventory()` is the only Catalog reference to an Inventory model, following the `tiers()` precedent.
  - `CreateProduct`/`DuplicateProduct` call `RecordOpeningStock`.
  - `StoreProductListQuery::RELATIONS` and `ProductDetailQuery` load `sku.inventory`.
  - `DashboardProductListQuery` takes `lowStock` and filters through `Inventory\Queries\LowStockQuery::constrain()`.
  - Factories gained `SkuFactory::withStock()` and `ProductFactory::withSku(..., onHand:, reserved:)`.
- Arch boundaries added in `tests/Arch/ModulesBoundariesTest.php`:
  - Inventory models are used only by the ledger, Inventory queries/resources, `AdjustStock` (reads the returned movement for its audit), `Sku::inventory()` and factories.
  - Inventory uses Catalog only via `Product`, `Sku`, `FilteredProductsQuery`, `ProductFilterData` and enums.
  - Catalog uses Inventory only via actions and queries.
  - Pricing, Tenancy and Audit don't use Inventory.