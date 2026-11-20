# V2 Task 18 — Costing: purchase cost, average cost, adjustment reasons, stocktake

Status: **done** (2026-11-16/17): slices A–D. Ideas taken from the ILORA ERP's inventory and costing (`D:\algoriza\ilora`), cut down to what a wholesale merchant uses: one weighted average cost per SKU, no double entry, no FIFO layers, no suppliers. Requires V2 Tasks 01 (returns), 13 (selling units), 15 (bundles), 16 (reports). Read `CLAUDE.md`, `rules/*.md` (incl. `04-business-truth.md`), then this file.

## Slices

| Slice | What | Decision |
| ----- | ---- | -------- |
| A | Average cost per SKU, receiving goods at a price (`receive` movement), setting the cost by hand, cost frozen on every movement and on delivered order lines, returns at the frozen cost | D96, BR-INV09 |
| B | Fixed reasons for stock adjustments (جرد، كسر أو تلف، انتهاء صلاحية، تصحيح خطأ تسجيل، بضاعة وُجدت، أخرى + ملاحظة) | D97 |
| C | Profit and margin in the existing sales and product reports, from the frozen costs | D98 |
| D | Stocktake posts the difference against the count's starting figure instead of overwriting; the base unit is locked while stock exists | D99 |

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

## Slice B — delivered

- `Inventory\Enums\AdjustmentReason` (`stocktake`, `damaged`, `expired`, `recording_error`, `found`, `other`, and the import's own `import`); `inventory_movements.reason_code` (migration `2026_11_07_100000_add_adjustment_reason_codes`, check: known codes, adjustments only). Old import adjustments (text «استيراد») backfilled to `import`; every other old adjustment keeps its text and no code (D97 e).
- `StockAdjustmentData(newOnHand, reason, note)`; the movement text is `text()` = label, then «: note». `StockLedger::adjust` takes the code; `AdjustStock` audits the code and the note. The catalog import passes `AdjustmentReason::Import` (the old `ImportCatalogRow::STOCK_REASON` constant is gone; its label is the same text). The demo seeder counts with `stocktake`.
- API: adjust and bulk-adjust take `reason` (merchant codes only; `import` is 422) and `note` (≤ 150, required for `other`); movements add `reason_code`.
- Writers of `reason_code` (rules/04 §3): only `StockLedger::record`, from `adjust`.
- Frontend: `ReasonFields` (select from the list + note, «اختر السبب» / «اكتب ما حدث») in the adjust sheet and the bulk form (one reason and note for the batch).
- Tests: backend `composer test` green, 6212 passed (+11); frontend 1034 passed (+2 (reasons list without import, «أخرى» needs a note), existing adjust/bulk tests send codes.

### Manual checks (slice B)

| Call | Answer |
| ---- | ------ |
| `POST …/1/adjust {"new_on_hand":735,"reason":"damaged","note":…}` | 200; the movement −5, `reason_code` "damaged", `unit_cost` "5.2618" (the average of that moment) |
| `POST …/1/adjust {"new_on_hand":730,"reason":"import"}` | 422 `validation_failed` on `reason` |
| `POST …/1/adjust {"new_on_hand":730,"reason":"other"}` | 422 `validation_failed` on `note` |
| the note «وقع» sent as a UTF-8 file (`curl --data-binary @file`) | stored `كسر أو تلف: وقع` (hex checked in psql). An Arabic note typed on the Git Bash command line arrives as `???`: Windows turns curl's arguments into the ANSI code page before they leave; the app is not involved |

## Slice C — delivered

- `SalesFactsQuery::costs()` (lines that left the stock, at their frozen cost, against the top-level SKU and product) and `returnedCosts()` (live returns at the returned line's cost). Sales analytics adds `profit { cost, profit, margin, uncosted_lines }`; product rows add `cost, profit, margin, uncosted_lines`; the product export adds four columns. No partial profit: null while any line in scope lacks a cost (D98 c).
- Frontend: المبيعات shows تكلفة البضاعة المباعة / الربح / هامش الربح, «غير مكتمل» and the hint with the count when costs are missing; المنتجات adds التكلفة / الربح / هامش الربح columns.
- Hand-computed tests (rules/04 §4), book cost 40.00 and pen 20.00:
  - Sales 8–10 Oct: cost 140 out − 40 back = 100, net 226 → profit 126.00, 55.75 %.
  - Books only: 80, 181 → 101.00, 55.80 %.
  - The pen uncosted: profit null, 1 line.
  - Products: BK-1 80 / 101.00 / 55.80; SET-1 (components) 160 / 140.00 / 46.67; PN-1 20 / 25.00 / 55.56.
- Tests: backend `composer test` green, 6216 passed (+4 profit cases, shape tests and the export updated); frontend 1036 passed (+2).

### Manual checks (slice C)

| Call | Answer |
| ---- | ------ |
| `GET /api/dashboard/reports/sales-analytics?from=2026-10-01&to=2026-10-31` (alnoor demo, every line delivered before costing) | `profit` `{ cost "0.00", profit null, margin null, uncosted_lines 76 }`; 76 = the SQL count of the stock-holding lines delivered in Cairo October |

## Slice D — delivered

- `StockLedger::adjust(..., expectedOnHand)`: On Hand += counted − expected; equal = no-op; below zero `Inventory\Exceptions\CountOutdated` (409 `count_outdated`); below Reserved still `BelowReserved`. `StockAdjustmentData::expectedOnHand`, requests take `expected_on_hand` (single and per bulk item), the audit adds `counted` and `expected_on_hand`, bulk skips `count_outdated`.
- `Catalog\Actions\SaveProductSkus` refuses a base unit change of a SKU that `Inventory\Queries\StockHeldQuery::holds()` (on hand or reserved > 0): `Catalog\Exceptions\BaseUnitLocked`, 422 on `{path}.base_unit_id`.
- Frontend: the adjust sheet sends the On Hand it opened with, the bulk form the On Hand of its page; «تغيّر الرصيد كثيرًا منذ بدء الجرد، أعد العدّ» for a skipped stale count; `count_outdated` in the error copy.
- Hand-computed tests (rules/04 §4):
  - 50 + (45 − 50) = 45.
  - 10 sold meanwhile: 40 + (45 − 50) = 35, not 45.
  - A return meanwhile: 56 + (52 − 50) = 58.
  - Counted = expected: no-op.
  - 5 + (2 − 10) = −3 → 409.
  - 50 + (40 − 50) = 40 < 45 reserved → below reserved.
  - HTTP: 100 + (108 − 110) = 98, audited with both figures.
  - The base unit: refused while stock is held, allowed at zero.

- Tests: backend `composer test` green, 6234 passed (+18); frontend 1038 passed (+2). Whole task on top of v2: backend 6097 → 6234 (+137), frontend 1022 → 1038 (+16).

### Manual checks (slice D)

| Call | Answer |
| ---- | ------ |
| `POST …/1/adjust {"new_on_hand":738,"reason":"stocktake","expected_on_hand":740}` with 733 on hand | 200, `on_hand` 731 = 733 + (738 − 740), worked out before the call |
| `POST …/1/adjust {"new_on_hand":0,"reason":"stocktake","expected_on_hand":2000}` | 409 `count_outdated`, details `{ expected_on_hand 2000, counted 0, on_hand 731 }` |
| The base unit lock | not exercised live (needs a full product payload); covered by `SaveProductSkusTest` |
