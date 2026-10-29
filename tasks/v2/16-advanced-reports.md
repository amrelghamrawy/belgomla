# V2 Task 16 — Advanced reports

Status: **done** (2026-10-29, commit: see log). Five advanced reports in `Reports\Queries` on one set of line facts (`SalesFactsQuery`: top-level delivered lines with gross / line discount / order-discount share, component lines, live returned lines), each with `ReportFiltersData{from, to, group, category_id, customer_group_id}` and cached 10 minutes per tenant + filters + page (`Reports\Services\ReportCache`, `generated_at`): `SalesAnalyticsQuery` (gross, discounts, returns, net per bucket, average order, previous period, by section and customer group), `ProductPerformanceQuery`, `CustomerPatternsQuery`, `InventoryAnalyticsQuery` (+ summary), `CollectionsReportQuery`; endpoints `GET /api/dashboard/reports/{sales-analytics|products|customers|inventory|collections}` and `/{name}/export?format=xlsx|csv` (`Reports\Services\ReportExporter` through Catalog's `Spreadsheet`), all `reports.view`. Indexes `orders (tenant_id, status, delivered_at)` and `inventory_movements (tenant_id, type, created_at)`. Frontend: `/dashboard/reports` tabs نظرة عامة / المبيعات / المنتجات / العملاء / المخزون / التحصيل with presets (this week from Saturday, this month, this season from 1 Aug), filters, «آخر تحديث», «تصدير» (Excel/CSV), the sales trend line with the previous period, top-20 bar list, aging stacked bar. D94. Requires V2 Tasks 01, 02, 05, 06, 08, 11, 15. Read `CLAUDE.md`, `rules/*.md`, then this file.

## Delivered (deviations from the plan below)

- **Shared facts, not five independent queries:** `SalesFactsQuery` is the single place that reads order/return lines, so every report agrees: net of a range = Σ delivered order totals − returns of the range (checked in tests and on the dev data). Gross = list price × what went out; discounts = line discounts + the order discount shared over the lines by their totals (D85); removed lines out, delivered quantity counts, pre-orders never.
- **Bundles (D93):** sales count the bundle line only; the product report gives the bundle its row with the revenue and each component its base units flagged `bundle_quantity_base` (a returned component's money goes against its bundle row, its units into the component's return rate); inventory counts component units as sold.
- **Choices recorded in D94:** current customer group (orders do not snapshot it); return rate = returned ÷ delivered base units of the range, each by its own date; customer rhythm over all non-cancelled orders ever, dormant after 45 days; dead stock = on hand and no delivery for more than 60 days ever; days of cover null = ∞; a filter that does not apply is ignored; exports are synchronous (every row, not a page).
- **Module boundaries:** Reports gained a `Services` folder (`ReportCache`, `ReportExporter`; arch test updated). It reads collections through the new `Collections\Queries\CollectionFlowQuery`, movements through `Inventory\Queries\MovementSummaryQuery`, group names through `CustomerGroupsQuery::names()`, the aging total through `OutstandingReportQuery::totals()`. `SalesGrouping` now owns the bucket SQL and the period list (shared by the V1 sales report). `Money::divide` / `Money::percentOf` (half-up, bcmath).
- **Response shape:** paginated reports keep Laravel's envelope with `from, to, generated_at` (and `sort` / `view, dormant_days` / `dead_days, summary`) as top-level keys; page size 20 (page 1 = top 20).
- **Frontend:** the old outstanding, low-stock and bundle tabs moved into التحصيل, المخزون and المنتجات; the V1 sales section is replaced by the المبيعات tab (the overview still uses `SalesChart`). The category filter is a NativeSelect tree (like the products list); the customer-group filter shows only for `pricing.customers` (that endpoint's permission), so the accountant role gets none.
- Tests: backend 6097 passed (+249: one hand-computed dataset per report incl. Cairo 21:59/22:00 UTC boundaries in November, Saturday weeks, returns bucketed by `returned_at`, bundles, removed/partial lines, pre-orders, category subtree and group filters, tenant isolation, cache TTL, query-count guards; HTTP endpoints with filter validation dataset, guest/role/cross-host, documented shapes, XLSX/CSV exports, 404 unknown report; resources, data, enums, services, Money, migration and rollback; permission matrix and security sweep rows). Frontend 1022 unit tests (+30); new e2e `reports.spec.ts` opens every tab with the season preset and downloads the Excel file on mobile and desktop; full e2e on a production build 54/54.
- Smoke against `php artisan serve` (gateway off, no worker, alnoor October 2026): every report 0.23–0.56 s; analytics net 366,821.21 = v1 sales net per week = Σ delivered totals 375,810.60 − returns 8,989.39 (SQL); discounts 1,763.40 = line 1,598.40 + order 165.00; Σ product revenues = net; collections net 248,562.01 = SQL; inventory sold 3,593 = −sale movements; customer #1 revenue 69,645.81 and average 13,979.84 checked by SQL; XLSX and CSV exports downloaded.

## Goal

The merchant understands the business beyond V1's four reports (D5): what sells, who buys, what does not move, and how money comes in. All figures are net of returns and discounts, on Cairo days (D60) with Saturday weeks (D58), exportable to XLSX/CSV.

Out of scope: a BI tool, custom report builder, forecasting (Later), platform-wide analytics.

## Read before coding

| What      | Where |
| --------- | ----- |
| Rules     | D5 reports, D58, D59, D60, BR-R* (returns), BR-C05..C08 (credit), BR-PR08 (discounts) |
| API       | `08-api-contract.md` §11 reports |

## Left by earlier tasks

- `Reports\Queries`: `OverviewQuery`, `SalesQuery` + `SalesGrouping`, `OutstandingReportQuery` (+ aging from Task 02), `LowStockReportQuery`. Frontend `ReportsPage.tsx`, `SalesChart.tsx`, `BarList.tsx`.
- Task 06 `Catalog\Services\Spreadsheet` writer (export). Task 05 `reports.view`. Task 11 customer groups. Task 15 bundle/child lines (report components at the child level, bundles separately).
- Arch: "orders models are used outside orders only by collections and reports".

## Deliverables

### 1. Docs

D94 into 06. 08 §11 gets the new endpoints; D5 is extended.

### 2. Reports (each a `Query` in `Reports\Queries`, with a `ReportFiltersData{from, to, group?, category_id?, customer_group_id?}`)

1. **Sales analytics** (`SalesAnalyticsQuery`): gross, discounts, returns, net per bucket; orders count; average order value; split by section kind (D68) and by customer group; comparison with the previous period of the same length.
2. **Product performance** (`ProductPerformanceQuery`): per product/SKU — quantity sold (base and selling units), net revenue, number of customers, return rate (returned / delivered qty), last sold at. Sort by revenue, quantity or return rate. Top 20 + paginated full list.
3. **Customer patterns** (`CustomerPatternsQuery`): per customer — orders, net revenue, AOV, first and last order, average days between orders, days since last order (a "dormant" flag after N days, default 45), outstanding and overdue (Task 02). Top customers and dormant customers views.
4. **Inventory analytics** (`InventoryAnalyticsQuery`): per SKU — on hand, available, stock value at base price (cost is not tracked; D94), units sold in the period, days of cover (available / average daily sales), dead stock (no sale in N days, default 60, with stock > 0), movement summary by type (opening, adjustment, sale, return).
5. **Collections report** (`CollectionsReportQuery`): collected per day/week/month by method (cash, credit, refunds negative), collected vs delivered, collection rate, aging snapshot (Task 02).

The performance work: indexes on `orders(tenant_id, status, delivered_at)`, `order_items(tenant_id, sku_id)`, `inventory_movements(tenant_id, type, created_at)` where missing (check `EXPLAIN` on the demo data with 2 months of activity from `tenant:demo`). Heavy reports are cached for 10 minutes per tenant + filters, with a TTL only and no write-triggered busting (D94).

### 3. Endpoints

`GET /api/dashboard/reports/{sales-analytics|products|customers|inventory|collections}?…` and `GET /api/dashboard/reports/{name}/export?format=xlsx|csv` (streamed through `Spreadsheet`). All need `permission:reports.view`.

### 4. Frontend

`/dashboard/reports` becomes tabs: نظرة عامة (V1 overview), المبيعات, المنتجات, العملاء, المخزون, التحصيل. Each has filters (date range presets: this week, this month, this season = from 1 Aug), a chart where it helps (sales trend line with previous period, top products bar list, aging stacked bar), a table, and "تصدير". Charts follow the dataviz conventions used by `SalesChart.tsx`.

## Test list

- One `Unit/Queries/<Report>Test` per report with a hand-computed dataset: net = gross − discounts − returns; returns in a different period than delivery (bucketed by `returned_at`); bundles counted once at bundle level and once per component in product performance (flagged); Cairo day boundaries at 21:59/22:00 UTC; Saturday weeks; group split.
- `Unit/Queries/InventoryAnalyticsQueryTest`: days of cover with zero sales (∞ shown as null), dead stock threshold.
- `Unit/Queries/CustomerPatternsQueryTest`: dormant flag, average interval with one order (null).
- `Http/Dashboard/Reports/AdvancedReportsTest`: filters validation, permission matrix, export content type and header row.
- Performance guard: each report runs under a query-count assertion (no N+1).
- Frontend: tab rendering with fixtures, export button; e2e: open each tab on the demo store.

## Slices

1. Docs + sales analytics + collections report.
2. Product performance + inventory analytics.
3. Customer patterns.
4. Export + frontend.

## Definition of done

Green suites; on `tenant:demo` data every report loads under 1 s locally and the numbers reconcile (net sales of a month = Σ delivered order totals − returns of that month); docs updated.

## Open decisions (proposed)

- **D94** No purchase cost is tracked, so stock value and margins use the base selling price ("قيمة المخزون بسعر البيع"), labelled as such. Reports are cached for 10 minutes per tenant and filter set; a merchant who just recorded something may see the change after the cache expires (the page shows "آخر تحديث").
