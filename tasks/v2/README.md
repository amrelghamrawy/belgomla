# V2 Task Board

V2 = "Commercial Expansion" (`src-md-files/03-v1-v2-roadmap.md` §V2), limited to **core V2**: what the school-book wholesale trade needs once V1 is live. One task = one session (or a few). Execute in order unless the dependency list below says a task is free; each task assumes the earlier ones are merged. Before starting any task read `CLAUDE.md` and `rules/*.md`, then the task file.

| #  | Task                                                   | Module(s)                                  | Status  | Commit |
| -- | ------------------------------------------------------ | ------------------------------------------ | ------- | ------ |
| 00 | [V1 close-out (gate)](00-v1-closeout.md)               | deploy/, wa-gateway/, frontend             | in progress (repo side done, go-live pending) | 1b252a4 |
| 01 | [Returns (المرتجع)](01-returns.md)                     | Orders, Inventory, Collections, Reports    | done    | d0a8beb |
| 02 | [Credit terms & account statement](02-credit-and-statement.md) | Customers, Collections, Orders, Reports | done    | 75e2782 |
| 03 | [Customer order notifications](03-order-notifications.md) | Messaging, Orders, Tenancy settings     | done (live VM check pending) | e068bd1 |
| 04 | [Pending order auto-expiry](04-pending-expiry.md)      | Orders, Tenancy settings                   | done    | 1e888a1 |
| 05 | [Staff accounts, roles & permissions](05-staff-roles.md) | Tenancy, every dashboard route, frontend | done    | ef9907c |
| 06 | [Catalog import/export](06-import-export.md)           | Catalog, Pricing, Inventory                | done    | see log |
| 07 | [Merchant-placed orders](07-merchant-orders.md)        | Orders, Customers                          | done    | see log |
| 08 | [Manual discounts](08-discounts.md)                    | Orders, Pricing, Collections               | done    | see log |
| 09 | [Order editing & partial fulfilment](09-order-editing.md) | Orders, Inventory                       | done    | see log |
| 10 | [Pre-orders on Coming Soon](10-pre-orders.md)          | Orders, Catalog, Customers (cart)          | done    | see log |
| 11 | [Customer groups & customer pricing](11-customer-pricing.md) | Customers, Pricing, Catalog          | done    | see log |
| 12 | [Promotional pricing](12-promotions.md)                | Pricing, Catalog                           | done    | see log |
| 13 | [Multiple selling units per SKU](13-multi-unit-skus.md) | Catalog, Pricing, Inventory, Customers, Orders | done    | see log |
| 14 | [Product variants](14-variants.md)                     | Catalog, Pricing, frontend                 | done    | see log |
| 15 | [Bundles & full-grade sets](15-bundles.md)             | Catalog, Pricing, Inventory, Orders        | done    | see log |
| 16 | [Advanced reports](16-advanced-reports.md)             | Reports                                    | done    | see log |
| 17 | [V2 release](17-v2-release.md)                         | docs, seeders, deploy                      | planned |        |

## Why this order

- **00** V2 code must not ship to a store that is not live yet. The go-live list and task 12's deploy slice come first.
- **01 Returns.** The first V2 feature by decision (D20). End-of-season returns are standard in the school-book trade, and the schema hooks (`return` movement, `order_returns` name) are already reserved.
- **02 Credit & statement.** It needs returns, because a statement has to show them. Credit terms (آجل) are how Egyptian B2B buyers pay. Together with 01 it finishes the money side.
- **03 Order notifications.** Cheap, because the messaging stack, store number and fallback chain exist. It removes the merchant's manual "your order is on the way" messages.
- **04 Pending expiry.** Small. It uses 03 to tell the customer. It frees stock that stale pending orders hold (BR-INV07).
- **05 Roles.** Must come before the tasks that give the merchant side new sensitive powers (manual orders, discounts, editing, credit limits), so each of them adds a permission rather than retrofitting one.
- **06 Import/export.** Independent. It comes early because onboarding a publisher catalog by hand is the biggest adoption blocker, and season rollover (D19) needs it.
- **07 → 08 → 09** are order mutations, in rising complexity: create on behalf, then change price, then change lines and quantities.
- **10 Pre-orders.** They reuse 07's line-source refactor and 09's editing for orders that cannot be fully converted.
- **11 → 12** are pricing layers: who gets which price, then when.
- **13 → 14 → 15** are SKU-structure rewrites. They touch the most code (cart, checkout, snapshots, inventory, storefront), so they come last, when everything else is stable. Units come before variants (both reshape the SKU), and bundles come after both (they compose SKUs).
- **16 Reports.** Last among the features, so they can be built on returns, discounts, credit and groups.
- **17** closes V2.

## Dependencies

```
00 ─► everything
01 ─► 02 ─► 16
03 ─► 04, 07, 09, 10
05 ─► 07, 08, 09, 11, 16
06 ─► 13 (format bump), 16 (exporter)
07 ─► 08 ─► 09 ─► 10
11 ─► 12
13 ─► 14 ─► 15
```

Free to run out of order: 06 at any point after 00; 03 and 04 in parallel with 01/02.

## Deferred to after V2 (no task files)

Each needs a product decision before it gets a task:

- **Payments**: online gateways, bank transfer workflows, InstaPay/wallets (BR-C01).
- **Delivery**: shipping companies, delivery fees, zones, driver assignment, tracking (05 §8 Merchant Delivery stays thin).
- **Subscription**: tenant plans, billing, renewals, status automation.
- **Telegram**, and broadcast/marketing WhatsApp. The latter needs the official Meta Cloud API (D70).
- **VAT / taxes**: D3 stands (prices final). E-invoicing is "Later".
- **Customer-specific catalogs** (hide products per customer/group). 11 covers prices only.

## Rules for the board

- A task is "done" only when its file says so with the commit hash and `composer test` (and `npm run typecheck && npm run lint && npm test` for frontend work) is green.
- Decisions marked **(proposed)** in a task file are copied into `src-md-files/06-open-decisions.md` with their default in the task's first slice (rules/00). Numbers D74–D94 are reserved for them (each task file lists its own).
- If a task reveals a product decision, add it to `06-open-decisions.md` with a default and continue.
- If a task needs something from a later task, stub nothing: move the smallest necessary piece into the current task and record it in both task files.
- Every task that changes the API updates `backend/openapi/openapi.yaml` → `php artisan api:openapi` → `npm run api:types`.
