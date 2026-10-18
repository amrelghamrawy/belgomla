# V2 Task 16 — Advanced reports

Status: **planned**. Requires V2 Tasks 01, 02, 05, 06, 08, 11, 15. Read `CLAUDE.md`, `rules/*.md`, then this file.

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
