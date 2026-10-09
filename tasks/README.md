# V1 Task Board

One task = one session (or a few). Execute strictly in order; each task assumes the previous ones are merged. Before starting any task read `CLAUDE.md` and `rules/*.md`, then the task file.

| #  | Task                                   | Module(s)                     | Status      | Commit |
| -- | -------------------------------------- | ----------------------------- | ----------- | ------ |
| 0  | Tenancy + Audit (done in session 1)    | Tenancy, Audit                | done        | 82f6b33 |
| 1  | [Catalog](1-catalog.md)                | Catalog                       | done        | dcbbd44 |
| 2  | [Pricing](2-pricing.md)                | Pricing                       | done        |        |
| 3  | [Inventory](3-inventory.md)            | Inventory                     | not started |        |
| 4  | [Messaging](4-messaging.md)            | Messaging                     | not started |        |
| 5  | [Customers](5-customers.md)            | Customers (+ cart)            | not started |        |
| 6  | [Orders & Checkout](6-orders.md)       | Orders, Notifications         | not started |        |
| 7  | [Collections & Reports](7-collections-reports.md) | Collections, Reports, Audit API | not started |        |
| 8  | [Frontend foundation](8-frontend-foundation.md) | frontend/ (Next.js)   | not started |        |
| 9  | [Storefront UI](9-storefront-ui.md)    | frontend/ storefront          | not started |        |
| 10 | [Dashboard UI](10-dashboard-ui.md)     | frontend/ dashboard           | not started |        |
| 11 | [Release](11-release.md)               | infra, seed, go-live checks   | not started |        |

Rules for the board:

- A task is "done" only when its file says so with the commit hash and `composer test` (and `npm test` for frontend tasks) is green.
- If a task reveals a product decision, add it to `src-md-files/06-open-decisions.md` with a default and continue.
- If a task needs something from a later task, stub nothing: move the smallest necessary piece into the current task and record it in both task files.
