# V2 Task 18 — Costing: purchase cost, average cost, adjustment reasons, stocktake

Status: **slice A done** (2026-11-16); slices B–D to follow. Ideas taken from the ILORA ERP's inventory and costing (`D:\algoriza\ilora`), cut down to what a wholesale merchant uses: one weighted average cost per SKU, no double entry, no FIFO layers, no suppliers. Requires V2 Tasks 01 (returns), 13 (selling units), 15 (bundles), 16 (reports). Read `CLAUDE.md`, `rules/*.md` (incl. `04-business-truth.md`), then this file.

## Slices

| Slice | What | Decision |
| ----- | ---- | -------- |
| A | Average cost per SKU, receiving goods at a price (`receive` movement), setting the cost by hand, cost frozen on every movement and on delivered order lines, returns at the frozen cost | D96, BR-INV09 |
| B | Fixed reasons for stock adjustments (كسر، منتهي الصلاحية، خطأ تسجيل، بضاعة وُجدت، أخرى + ملاحظة) | — |
| C | Profit and margin in the existing sales and product reports, from the frozen costs | — |
| D | Stocktake posts the difference against the count's starting figure instead of overwriting; the base unit is locked while stock exists | — |

## Slice A — delivered

- **Schema** (`2026_11_06_100000_add_stock_costing`): `sku_inventory.avg_cost`, `inventory_movements.unit_cost`, `order_items.unit_cost`, all `numeric(14,4)` per base unit, nullable, ≥ 0; movement type `receive` (check: a receipt always has a cost and a positive quantity). Nothing backfilled: existing averages start unknown, old movements and delivered lines keep null (rules/04 §5, D96 f).
- **StockLedger** stays the only writer of stock rows and movements:
  - `receive(sku, base units, cost, note, actor)` moves the average inside the row lock.
  - `setCost(sku, cost)` replaces it and returns the old one.
  - `deduct` and `adjust` record the average of that moment.
  - `restock`/`unrestock` take the order line's frozen cost (`restock` moves the average when the cost is known).
- **Writers of the new columns** (rules/04 §3):
  - `avg_cost` only `StockLedger::receive|setCost|restock`.
  - `inventory_movements.unit_cost` only `StockLedger::record`.
  - `order_items.unit_cost` only `Orders\Actions\ApplyTransition::moveStock` (from the `sale` movement the ledger returns).
- **Math** in `App\Support\UnitCost` (bcmath, half-up to 4 decimals): `perBaseUnit(price, factor)`, `average(onHand, average, received, cost)`.
- **Endpoints** (`inventory.adjust`):
  - `POST /api/dashboard/inventory/{sku}/receive` (idempotent): `{ quantity, unit_cost, sku_unit_id?, note? }`.
  - `PUT /api/dashboard/inventory/{sku}/cost`: `{ unit_cost, sku_unit_id? }`.
  - Both return the inventory row and are audited `stock.received` / `stock.cost_set`.
- **Who sees costs:** the row's `avg_cost` and each movement's `unit_cost` appear only for `inventory.adjust` or `reports.view` (owner, manager, warehouse, accountant; never sales, D96 g). The inventory report's movements add `receive`.
- **Frontend** (المخزون):
  - An «متوسط التكلفة» column when the data carries it.
  - «استلام بضاعة» and «تحديد التكلفة» for users with `inventory.adjust`, in a sheet that lists the SKU's units (the base unit first, then the larger units) and sends what was typed; the server converts.
  - A «التكلفة» column on the movements page.
  - `formatUnitCost` shows 2 decimals unless the last two are not zero.
- **Today the code does** (relied on, rules/04 §2):
  - Stock changes go only through `StockLedger` under a `FOR UPDATE` lock in `sku_id` order (`backend/app/Modules/Inventory/Services/StockLedger.php:42`).
  - Delivery deducts each stock-holding line (loose and component lines; the bundle line holds nothing) in `ApplyTransition::moveStock` (`backend/app/Modules/Orders/Actions/ApplyTransition.php:97-131`).
  - Returns restock in base units (`RecordReturn.php:159`); voids unrestock (`VoidReturn.php:75`).
  - Delivering zero of a line is refused (`OrderEditRefused`, validation).
- Tests: backend `composer test` green, 6201 passed (+104 on top of v2's 6097), covering:
  - `UnitCost` datasets with the BR's example (5.5000), uneven (5.3571), half-up on the last place, unknown and zero-stock cases.
  - The ledger: receive, adjust, deduct, restock with and without a cost, unrestock, setCost, opening.
  - A two-session concurrency test: the second receipt waits on the row lock, and the average counts both (5.5000).
  - Delivery freezing the cost on loose, short-delivered and bundle component lines; a later receipt not changing it.
  - Returns at the frozen cost moving the average (7.6250); a void at the same cost.
  - HTTP receive/cost: carton conversion, audit, validation dataset, foreign unit, ceiling, bundle 409, idempotency, tenant 404, visibility per role.
  - Data, the query, resources, the model key sets, the `*_cost` decimal:4 convention, the permission matrix and the security sweep.
- Frontend: typecheck, lint, vitest green, 1032 passed (+10 on top of 1022: `formatUnitCost`, the receive/cost form incl. Idempotency-Key, unit list, validation, the bundle refusal).

### Manual checks (slice A)

Against `php artisan serve`, alnoor demo data, owner session via `scripts/smoke.sh`:

| Call | Answer |
| ---- | ------ |
| `GET /api/dashboard/inventory/1` | 200, `on_hand` 725, `avg_cost` null |
| `POST …/1/receive {"quantity":10,"unit_cost":"6.00","note":"smoke"}` | 200, `on_hand` 735, `avg_cost` "6.0000" (no average yet → the cost paid) |
| `PUT …/1/cost {"unit_cost":"5.25"}` | 200, `avg_cost` "5.2500" |
| `GET …/1/movements?per_page=1` | the `receive` row «استلام بضاعة», +10, `unit_cost` "6.0000", reason "smoke" |
| `POST …/1/receive {"quantity":5,"unit_cost":"7.00"}` | 200, `on_hand` 740, `avg_cost` "5.2618" = (735 × 5.25 + 5 × 7) ÷ 740 = 3893.75 ÷ 740, worked out before the call |
| `POST …/1/receive {"quantity":0,…}` | 422 `validation_failed` on `quantity` |
