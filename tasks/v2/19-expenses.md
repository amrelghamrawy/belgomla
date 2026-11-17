# V2 Task 19 — Operating expenses and net profit (المصروفات وصافي الربح)

Status: **planned** (2026-11-17). Requires V2 Task 18 slice C (gross profit from the frozen costs, D98) to be merged; slice B (adjustment reasons, D97) gives the stock losses their reasons. Read `CLAUDE.md`, `rules/*.md` (incl. `04-business-truth.md`), then this file.

## Why

Task 18 tells the merchant what the goods they sold cost them (gross profit). It cannot tell them whether the shop made or lost money over a period. For that, it also needs what the shop spends to run: rent, salaries, transport, electricity and so on. Today nothing in the system records these. The roadmap keeps "advanced accounting" for **Later** (`src-md-files/03-v1-v2-roadmap.md:146`, `05-module-scope.md:165`). This task is not accounting. There are no accounts, no journal entries and no double entry: just a list of what was spent, and one report that subtracts it.

## Slices

| Slice | What | Decision |
| ----- | ---- | -------- |
| A | Expense records: record, list, void; fixed categories; `expenses.manage` | D100 (proposed) |
| B | Net profit report (الأرباح والخسائر) for a range: net sales − cost of goods − stock losses − expenses | D100 (proposed) |

## D100 (proposed): operating expenses and net profit

Copy into `src-md-files/06-open-decisions.md` in slice A (README "Rules for the board"); the product owner confirms or changes the defaults.

(a) **An expense** (`expenses`, tenant-scoped) has:

| Field | Rule |
| ----- | ---- |
| `spent_on` | a business day `Y-m-d` (D60); not after today in the merchant's zone |
| `amount` | money, > 0.00, ≤ 10,000,000.00, numeric(12,2) |
| `category` | one of the fixed codes in (b) |
| `note` | ≤ 200; required for `other` |
| `created_by` | the tenant user who recorded it |
| voiding | `voided_at`, `voided_by`, `void_reason` |

There are no attachments or receipts in this task.

(b) **Fixed categories, the same for every store** (as D97 a; no store-defined lists):

| Code | Label |
| ---- | ----- |
| `rent` | إيجار |
| `salaries` | رواتب وأجور |
| `transport` | نقل وشحن |
| `utilities` | كهرباء ومياه وإنترنت |
| `maintenance` | صيانة |
| `packaging` | تغليف ومستلزمات |
| `marketing` | دعاية وتسويق |
| `fees` | رسوم وضرائب |
| `other` | أخرى |

(c) **No edits.** A wrong expense is voided with a reason and entered again. This is how collections work (BR-C), and it keeps the history honest. Voided expenses stay listed, marked as voided, and count nowhere.

(d) **What is not an expense:**
- **Goods bought for sale.** These enter through «استلام بضاعة» (D96 b) and reach the profit as cost of goods when they are sold. Recording them here as well counts them twice. The form says so, and there is no "purchases" category.
- **The owner's own withdrawals.**
- **Paying back a customer.** That is a refund (BR-C03).

(e) **When an expense counts.** It counts on its `spent_on` day only, and is not spread over months: a year's rent paid at once falls in the month it was paid. This is a limitation. A merchant who wants it spread enters one expense per month. Recurring (automatic) expenses are out of scope.

(f) **Net profit is on delivery, not on cash.** Sales count when delivered and returns when recorded, the same as every report (D94, D98). Expenses count on `spent_on`. The report is therefore not a cash flow: money customers still owe is in it. The page says this in one line.

(g) **Stock losses.** These are the value of the range's `adjust` movements at the cost frozen on each one (D96 e): decreases (stocktake shortage, damage, expiry, recording error, other) less increases (found, stocktake surplus).
- `import` adjustments are excluded. They are data entry, not a loss.
- Opening stock is excluded.
- An adjustment with no cost (`unit_cost` null) makes the losses unknown. It is counted in `uncosted_adjustments`, and the losses and the net profit are null, the same rule as D98's `uncosted_lines` (rules/04 §5). Expenses are still shown.

(h) **Formula** (all amounts rounded half-up to 2 decimals after summing):
- gross profit = net sales − cost of goods (D98)
- net profit = gross profit − stock losses − expenses
- net margin = net profit ÷ net sales, in %; null when net sales is 0

If the gross profit is null (uncosted lines), the net profit is null and the report says why. A negative net profit is a loss and is shown as one («خسارة»).

(i) **Permissions.**
- A new `expenses.manage` («المصروفات») for recording, listing and voiding expenses. It goes to owner, manager (both get every new permission by `TenantUserRole::permissions()`) and accountant. Sales and warehouse do not get it.
- The net profit report needs `reports.view`, like every report.

(j) **Filters.** The report takes the range (and nothing else) from the shared report filters. The category and customer group filters do not apply, because rent belongs to no category. The report ignores them (08 §11) and the page hides them.

## Slice A — expense records

Backend, new module `app/Modules/Expenses/` (rules/02 §1):

- **Migration:**
  - The `expenses` table from D100 a, with a check that `category` is one of the known codes, `amount > 0`, and `note` is not null when `category = 'other'`.
  - Index `(tenant_id, spent_on)`.
- **Code:**
  - `Enums\ExpenseCategory` with `label()` and `toArray()`.
  - `Models\Expense`.
  - `Data\ExpenseData`.
  - `Actions\RecordExpense`, idempotent (Idempotency-Key, like receipts), audited `expense.recorded`.
  - `Actions\VoidExpense`, which locks the row, refuses a second void with `409 already_voided`, and is audited `expense.voided`.
  - `Queries\ExpenseListQuery`: paginated, newest `spent_on` first, filters for range, category and voided-or-not, with the live total of the filtered rows.
  - `Queries\ExpenseTotalsQuery`: live totals per category for a range. Slice B uses it, and Reports calls it across modules (rules/02 §1).
- **Permission:** `Permission::ExpensesManage` added to `Tenancy\Enums\Permission`, then to the accountant's list. Update the permission matrix test.
- **Routes** (`routes/api/dashboard/expenses.php`, `permission:expenses.manage`):
  - `GET /api/dashboard/expenses`
  - `POST /api/dashboard/expenses`
  - `POST /api/dashboard/expenses/{id}/void` (`ExpenseVoidController@store`)
  - `GET /api/dashboard/expenses/categories`
- **Report cache:** recording or voiding an expense must invalidate the tenant's report cache, as other writes do.
  - Not checked yet: how `Reports\Services\ReportCache` is busted today. Trace it (rules/04 §2) before writing this.
- **API:** `openapi.yaml` → `php artisan api:openapi` → `npm run api:types`.

Frontend:
- A new «المصروفات» page under the dashboard shell, shown only with `expenses.manage`. It has:
  - the list (date, category, amount, note, who recorded it, a voided badge), filters, and the filtered total;
  - «تسجيل مصروف», a sheet with date (default today), category, amount and note. Under the category it shows «ثمن البضاعة يُسجَّل من استلام بضاعة في المخزون، لا هنا»;
  - «إلغاء», which asks for a reason.

Tests (expected values worked out by hand from D100 before running, rules/04 §4):
- Validation datasets:
  - amount 0.00 and −1 refused, and 0.01 accepted (boundary);
  - 10,000,000.00 accepted and 10,000,000.01 refused;
  - a date after today is refused, and today is accepted;
  - `other` without a note is refused;
  - an unknown category is refused.
- Void: voiding twice gives 409. A voided expense leaves the totals. Another tenant's expense gives 404.
- Idempotency: the same key twice creates one row.
- Totals: rent 1,500.00 + transport 350.50 + transport 120.25 (voided) + salaries 600.00 → transport 350.50, all 2,450.50.
- Permissions per role: owner, manager and accountant get 200; sales and warehouse get 403.
- The audit rows, and the arch tests for the new module.

## Slice B — net profit report

- `Reports\Queries\ProfitAndLossQuery` for the range. It returns:
  - `net_sales` and the cost of goods, taken from the same facts as the sales analytics `profit` block (D98). It reuses `SalesFactsQuery::costs` / `returnedCosts` and does not write a second formula;
  - `gross_profit`;
  - `stock_losses` by reason code;
  - `uncosted_lines` and `uncosted_adjustments`;
  - `expenses` by category (from `Expenses\Queries\ExpenseTotalsQuery`);
  - `net_profit` and `net_margin`;
  - the same block for the previous range of equal length, like the sales analytics `previous` (D94).
- The stock losses come from `inventory_movements` of type `adjust` in the range, by `created_at` in the business zone, `reason_code <> 'import'`.
  - Not checked yet: whether Reports may read `inventory_movements` directly or must go through an Inventory query. Check the boundary arch test.
- Endpoint: `GET /api/dashboard/reports/profit-and-loss` (`reports.view`), cached like the other reports. Export to XLSX through `ReportExporter` (a new `ReportName`).
- Frontend: a «الأرباح والخسائر» tab in التقارير. It reads top to bottom:
  1. net sales
  2. − cost of goods
  3. = gross profit
  4. − stock losses (by reason)
  5. − expenses (by category)
  6. = net profit, shown in red with «خسارة» when negative

  It also shows a comparison with the previous range. When a figure is null it explains why: «بعض المبيعات بدون تكلفة» or «تسويات مخزون بدون تكلفة», with a link to set the cost.

Tests: one hand-worked case that touches every line of the formula. The numbers are chosen so that a wrong sign or a missed term gives a different answer:

```
net sales 10,000.00; cost of goods 7,250.00                        → gross profit 2,750.00
stock: damaged −5 at 5.2618 = −26.309; found +2 at 5.2500 = +10.50 → losses 26.309 − 10.50 = 15.809 → 15.81
expenses: rent 1,500.00 + transport 350.50 + salaries 600.00        → 2,450.50 (a voided 120.25 ignored)
net profit = 2,750.00 − 15.81 − 2,450.50 = 283.69; margin 283.69 ÷ 10,000.00 = 2.8369 % → 2.84
```

Plus:
- the loss case: the same with rent 3,000.00 gives −1,216.31;
- an `import` adjustment is ignored;
- an adjustment without a cost makes losses and net null, with expenses still shown;
- an uncosted sale line makes the net null;
- an expense on the range's first and last day counts, and one the day before the range does not (boundary, D60, in the business zone);
- net sales 0 gives a null margin;
- the category and customer group filters do not change the result;
- the export columns.

## Out of scope

- Accounts, a chart of accounts, journal entries, balance sheet, cash boxes and bank accounts (roadmap "Later").
- Recurring expenses, spreading an expense over months, attachments and receipt photos.
- Expenses per branch, warehouse or product.
- Bad debts (written-off customer balances), depreciation, VAT (D3).

## Manual checks (to fill in when done)

Against `php artisan serve`, alnoor demo data, owner session via `scripts/smoke.sh`: record two expenses, void one, then call the report for the month and compare it with the hand-worked figure.
