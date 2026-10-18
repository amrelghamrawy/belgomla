# V2 Task 04 — Automatic expiry of unconfirmed orders

Status: **planned**. Requires V2 Task 03. Read `CLAUDE.md`, `rules/*.md`, then this file.

## Goal

A tenant can choose to have pending orders cancel themselves after N hours without confirmation, releasing the stock they hold. Off by default: V1 behaviour (highlight after `stale_pending_hours`, D10) stays when the setting is empty.

## Read before coding

| What      | Where |
| --------- | ----- |
| Rules     | BR-INV04, BR-INV07, BR-O01..O04, BR-O03 (collections block cancel) |
| Tables    | `07-domain-model.md` §7 orders (CHECKs on status and `cancelled_by_type`), `tenant_settings` |
| Decisions | D7, D10 |

## Left by V1

- `Orders\Models\Scopes\Stale($hours)` (pending and `placed_at < now − hours`) and `DashboardOrdersQuery::staleBefore()`.
- `CancelOrder::handle(Order, CancelData, CancelledBy, ?actorId)` releases reservations, refuses when `collected_amount > 0` (`OrderHasCollections`) and audits. `CancelledBy`: `customer`, `tenant_user` (DB CHECK on `orders.cancelled_by_type`). A null actor in `TransitionOrder` already means `ActorType::System`.
- Commands are classes in `<Module>/Console/`, registered in `bootstrap/app.php` `withCommands`, and scheduled in `routes/console.php`. Schedule tests are in `tests/Console/ScheduleTest.php`.
- Task 03: `NotifyCustomerOfStatus` (the cancelled template).

## Deliverables

1. **Docs**: D81 into 06. BR-INV07 is rewritten (optional auto-expiry). 07 §7 gets `cancelled_by_type` = `system`. Settings list in 02 §M.
2. **Schema**: `tenant_settings.pending_expiry_hours smallint null CHECK between 1 and 720` (null = off; it must be ≥ `stale_pending_hours` when both are set, enforced by the request). `orders.cancelled_by_type` CHECK adds `'system'`.
3. **Classes**:
   - `CancelledBy::System` (`actorType()` → `ActorType::System`).
   - `Orders\Actions\ExpirePendingOrder::handle(Order)`: locks the order and re-checks it is still pending and old enough (a merchant may confirm it in between), then calls `CancelOrder` with reason "انتهت مهلة تأكيد الطلب" and `CancelledBy::System`. An order with collections is skipped and logged (BR-O03), never forced. It writes a merchant notification of type `order_expired` (a migration on the type CHECK).
   - `Orders\Queries\ExpirableOrdersQuery`: iterates tenants with the setting on (`withoutTenantScope` in CLI, commented per rules/00) and yields `Stale(hours)` orders per tenant.
   - `Orders\Console\ExpirePendingOrdersCommand` (`orders:expire-pending`) runs each tenant inside `Tenancy` context and processes orders one per transaction, so one failure does not stop the rest. It prints counts expired/skipped. Scheduled `everyFifteenMinutes()->withoutOverlapping()`.
   - `CancelOrder` notifies the customer for system cancels too (Task 03 hook: merchant **or system**).
4. **Endpoints**: settings `pending_expiry_hours` (GET/PATCH). The dashboard order resource shows `cancelled_by: system` with label "تلقائي".
5. **Frontend**: settings → ordering, "إلغاء الطلبات غير المؤكدة تلقائيًا بعد … ساعة" (off by default, with a hint that the stock is released). Orders list shows a countdown chip on pending orders when expiry is on. The bell renders `order_expired`.

## Test list

- `Unit/Actions/ExpirePendingOrderTest`: BR-INV07 expires a pending order older than the setting; releases stock; history actor system; skips a confirmed one (race); skips one with collections; notifies the merchant and the customer.
- `Console/ExpirePendingOrdersCommandTest`: only tenants with the setting; tenant isolation (tenant A's setting never expires tenant B); one failing order does not stop the others; output counts.
- `Console/ScheduleTest`: every 15 minutes, without overlapping.
- `Http/Dashboard/Settings/SettingsTest`: the bounds and ≥ stale hours.
- `Unit/Enums/OrdersEnumsTest`: `CancelledBy::System`.
- Frontend: settings field, countdown chip.

## Slices

1. Docs + schema + enum.
2. Action, query, command, schedule.
3. Settings API + frontend.

## Definition of done

Green suites; curl: set 1 hour, create an order, travel the clock in a test or update `placed_at` in a local DB, run the command → cancelled, stock released, message logged; docs updated.

## Open decisions (proposed)

- **D81** An expired order is **cancelled** by the system (`cancelled_by_type = system`, fixed reason), not given a new `expired` status. It keeps the state machine and every report unchanged.
