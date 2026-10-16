# V1 Task Board

One task = one session (or a few). Execute strictly in order; each task assumes the previous ones are merged. Before starting any task read `CLAUDE.md` and `rules/*.md`, then the task file.

| #  | Task                                   | Module(s)                     | Status      | Commit |
| -- | -------------------------------------- | ----------------------------- | ----------- | ------ |
| 0  | Tenancy + Audit (done in session 1)    | Tenancy, Audit                | done        | 82f6b33 |
| 1  | [Catalog](1-catalog.md)                | Catalog                       | done        | dcbbd44 |
| 2  | [Pricing](2-pricing.md)                | Pricing                       | done        | 5f90187 |
| 3  | [Inventory](3-inventory.md)            | Inventory                     | done        | ed057c0 |
| 4  | [Messaging](4-messaging.md)            | Messaging                     | done (live check pending) | 52b46bb |
| 5  | [Customers](5-customers.md)            | Customers (+ cart)            | done        | 04c5b76 |
| 6  | [Orders & Checkout](6-orders.md)       | Orders, Notifications         | done        | ed252a5 |
| 7  | [Collections & Reports](7-collections-reports.md) | Collections, Reports, Audit API | done        | 0c2f33a |
| 8  | [Frontend foundation](8-frontend-foundation.md) | frontend/ (Next.js)   | done        | c195b49 |
| 9  | [Storefront UI](9-storefront-ui.md)    | frontend/ storefront          | done (Lighthouse open) | 009b240 |
| 10 | [Dashboard UI](10-dashboard-ui.md)     | frontend/ dashboard           | done        | 33d231b |
| 11 | [Release](11-release.md)               | infra, seed, go-live checks   | repo side done (go-live pending) | a7c3884 |
| 12 | [Store WhatsApp number](12-tenant-whatsapp.md) | Messaging, wa-gateway/, settings UI | planned (D70) | |

Rules for the board:

- A task is "done" only when its file says so with the commit hash and `composer test` (and `npm test` for frontend tasks) is green.
- If a task reveals a product decision, add it to `src-md-files/06-open-decisions.md` with a default and continue.
- If a task needs something from a later task, stub nothing: move the smallest necessary piece into the current task and record it in both task files.
