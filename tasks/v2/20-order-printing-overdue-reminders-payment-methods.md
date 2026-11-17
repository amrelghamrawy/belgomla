# V2 Task 20 — Order printing, overdue reminders, payment methods

Status: **planned** (2026-11-17). Three small, independent slices about getting an order out of the door and getting its money in. The ideas come from ILORA (`D:\algoriza\ilora\backend\internal\modules`):
- `sales/invoice_batch_print`, `inventory/pick_pack`, `inventory/picking` (printing);
- `sales/dunning` (overdue reminders);
- `sales/collection_methods` (payment methods).

They are cut down to what a wholesale store that delivers and collects by itself uses: no accounting, no treasuries, no payment gateway.

Requires V2 Tasks 02 (credit terms, BR-C06), 03 (order messages, BR-N03), 05 (permissions), 09 (removed and delivered lines) and 15 (bundles). Read `CLAUDE.md`, `rules/*.md` (incl. `04-business-truth.md`), then this file.

## Why

- **Printing.** The driver needs a paper with the goods: what is in the order, the address, what to collect. The store needs one picking list for the day's orders in the season. Today the printable pages are the customer statement and the price list only (`frontend/components/shared/LedgerTable.tsx`, `components/store/PriceListView.tsx`). An order cannot be printed.
- **Overdue reminders.** Collecting credit sales (آجل) is the merchant's biggest daily chore. The system already knows what is overdue (BR-C06), but it tells only the merchant, never the customer.
- **Payment methods.** Wholesale customers pay more and more by InstaPay, wallets (Vodafone Cash and the like) and bank transfer. Today every payment is recorded as cash (`RecordCollection.php:62`), so the collections report's «نقدي» is wrong, and nothing keeps the transfer's reference.

## Slices

| Slice | What | Decision | Rule |
| ----- | ---- | -------- | ---- |
| A | Print an order (invoice or delivery note, one or many at once) and a consolidated picking list | D101 (proposed) | BR-O15 (new) |
| B | WhatsApp reminders for overdue balances: automatic per store setting, and a manual «ابعت تذكير» | D102 (proposed) | BR-C09 (new) |
| C | Record how a payment came in (cash, InstaPay, wallet, bank transfer) with its reference | D103 (proposed) | BR-C10 (new) |

Any order, one commit each. Copy D101–D103 into `src-md-files/06-open-decisions.md` with their defaults in the first slice you do (rules/00). Add each new BR to `02-business-rules.md` in its own slice.

**Numbering note.** Task 18 used D98–D99. Task 19 (expenses) holds D100. This task holds D101–D103. Task 21 (reorder, link previews, return reasons) holds D104–D106.

## Today the code does (rules/04 §2)

- **Order data.** Orders carry snapshots of the delivery name, phone and address (`delivery_name`, `delivery_phone`, `delivery_address` jsonb, BR-O10), the customer's note, `placed_at`, `delivered_at`, `due_at` (`07-domain-model.md` §7 orders).
  - Statuses: `pending → confirmed → preparing → out_for_delivery → delivered`, or `cancelled` (§11).
  - Lines carry their price and unit snapshots. Removed lines have `removed_at`. A delivered line has `delivered_quantity`. A bundle line (`is_bundle`) holds no stock; its components (`parent_item_id`) do. A pre-order line is `reserved = false` (§7 order_items).
- **SKU data.** SKUs have no shelf or bin location (none in `07-domain-model.md` §3), so a picking list cannot be sorted by place in the store.
- **Overdue.**
  - Overdue = delivered, past `due_at`, net balance left (BR-C06, `02-business-rules.md:806-808`).
  - Computed per customer by `Collections\Queries\OverdueQuery` (`backend/app/Modules/Collections/Queries/OverdueQuery.php:14`), used by the statement summary (`CustomerStatementQuery.php:50`).
  - Aged over all customers by `Reports\Queries\OutstandingReportQuery` (header comment, `:1-8`).
- **Customer messages.**
  - Go through `Messaging\Services\Messenger` with a `MessagePurpose`. Today the purposes are `otp`, `invitation`, `system_alert`, `order_status` (`backend/app/Modules/Messaging/Enums/MessagePurpose.php:13-16`).
  - Order messages are sent by `Orders\Actions\NotifyCustomerOfStatus`, **after commit**, in the store's own words, skipping customers who opted out (`customers.order_notifications_opt_out`, BR-N03 `02-business-rules.md:925`). A customer over the per-phone limit is logged, not failed (D79: 10 per hour per tenant+phone for order messages).
  - Scheduled commands live in `backend/routes/console.php` (`orders:expire-pending` every 15 minutes is the model to copy).
- **Collections.**
  - `CollectionMethod` has `cash` and `credit` (`backend/app/Modules/Collections/Enums/CollectionMethod.php`).
  - The DB check `collections_method_known` allows exactly those two (`database/migrations/2026_10_22_100000_add_credit_terms_and_customer_credits.php:73-74`).
  - `RecordCollection` always writes `cash` (`backend/app/Modules/Collections/Actions/RecordCollection.php:62`), and so does `RecordRefund` (`RecordRefund.php:38`). `credit` is written by the customer-credit actions (BR-C07).
  - The request takes `amount`, `collected_at`, `note` only (`RecordCollectionRequest.php:23-25`).
  - The collections report splits payments into `cash` and `credit` by method (`backend/app/Modules/Reports/Queries/CollectionsReportQuery.php:99-100`).
  - Routes: `backend/routes/api/dashboard/collections.php:21-27`, all under `collections.manage`.

Not checked: which of `ApplyCustomerCredit` / `KeepAsCredit` writes `method = credit` and how. Slice C lists every writer before changing the check (rules/04 §3).

---

## Slice A — Printing orders and picking lists

### D101 (proposed) — Printed order documents

- **(a) Two documents, both browser-printed A4** (like the statement; PDF is the browser's «Save as PDF»):
  1. **The order** («فاتورة طلب #1024»), with a switch «بدون أسعار» that prints it as a delivery note («إذن تسليم») for the driver or the store.
  2. **The picking list** («كشف تجهيز») for several orders at once.

  Thermal 80 mm receipts are out of scope.
- **(b) Not a tax invoice.** No tax fields, no tax number, no QR (D3 prices are final; e-invoicing is Later). The footer says «هذا المستند ليس فاتورة ضريبية». When the store gets e-invoicing, the title can change.
- **(c) The order document shows:**
  - **Header:** the store's logo, name, phone, WhatsApp and address.
  - **Order:** number, placed date (Cairo), status label, due date when there is one.
  - **Customer:** name and phone, then the delivery name, phone and address snapshot, and the customer's note.
  - **Lines:** non-removed lines, in order. Each line shows:
    - product / variant name, code, unit;
    - quantity: the `delivered_quantity` once delivered, else the ordered quantity;
    - unit price and line total.

    A bundle line is printed with its components indented under it, without prices.
  - **Totals:** items total, discounts, order total, returned, collected, **remaining**.
  - **«رصيد سابق»**: the customer's outstanding on their other orders. It is printed when the store turns it on in the print dialog (default on) and the user holds `collections.manage` or `customers.credit`. The amount is the statement summary's `outstanding` less this order's remaining.
  - **Signature:** a box «توقيع المستلم».

  Pre-orders print the same way with the title «حجز #…». Cancelled orders print with a «ملغي» stamp.
- **(d) Several orders at once.** Tick orders in the list, or use the list's current filter (≤ 50 orders), → one print with a page break per order.
- **(e) The picking list:**
  - covers the chosen orders (≤ 100), excluding cancelled orders and pre-orders (they hold no stock);
  - counts only lines that hold stock: loose lines and bundle components, not bundle lines, not removed lines;
  - groups by SKU **and unit**, adding the quantities. Each row: product / variant name, code, unit, total quantity, and the orders it goes to («#1024 × 3، #1031 × 5»);
  - sorts by the product's category, then product name, then code (no shelf locations, see above);
  - ends with a summary: orders count, lines count, and the base units per category.
- **(f) Who.** `orders.view` prints. Prices and «رصيد سابق» follow (c). The warehouse role sees the delivery note and the picking list, but no prices: the switch is forced on for users without `orders.manage` or `collections.manage`.
- **(g) Nothing is written.** Printing records nothing: no audit row, no «printed» flag.

### BR-O15 — Printed order documents (new, `02-business-rules.md` §H)

Write D101 (a)–(f) as the rule.

### A.1 Backend

Both endpoints are reads (Queries) under `orders.view`, take order numbers (BR-O09), and refuse an unknown or another tenant's number as a whole with `404` (BR-T04).

- `GET /api/dashboard/orders/print?numbers[]=1024&numbers[]=1031` (1–50):
  - each order with its lines, components nested under their bundle line, totals, the store header and `previous_balance` when allowed (D101 c, f);
  - Query `Orders\Queries\PrintableOrdersQuery`, a fixed number of queries whatever the count (no N+1);
  - store settings and outstanding come in through their modules' queries (the boundary test, `tests/Arch/ModulesBoundariesTest.php`).
- `GET /api/dashboard/orders/picking?numbers[]=…` (1–100):
  - Query `Orders\Queries\PickingListQuery`, aggregated in SQL (`group by sku_unit`);
  - `meta.skipped`: the cancelled and pre-order numbers left out.
- OpenAPI → `php artisan api:openapi` → `npm run api:types`.

### A.2 Frontend

- Print pages: `/dashboard/orders/print?numbers=…` and `/dashboard/orders/picking?numbers=…`.
  - Print CSS: A4, page break per order, no dashboard shell, like `LedgerTable`'s print rules.
  - They open in a new tab and call `window.print()` once the data is in.
- The order page: «طباعة» with the switches «بدون أسعار» and «رصيد سابق».
- The orders list: «طباعة الطلبات» and «كشف تجهيز» on the bulk bar for the ticked orders, or for the filter's first 50/100.
- Arabic copy in `lib/copy/ar-dashboard.ts`.
- Numbers in Arabic-Indic digits, like the statement. Money via the existing formatter.

### A.3 Tests

Picking example, worked out by hand:
- order #1024 (confirmed): A × 3 cartons, B × 10 pieces, and a bundle S × 2 whose components are A × 1 carton and C × 4 pieces;
- order #1031 (preparing): A × 5 cartons, and A × 12 pieces (same SKU, another unit);
- order #1040: cancelled;
- order #1050: a pre-order.

Expected rows:
- A cartons = 3 + 2 + 5 = **10** (#1024 × 5, #1031 × 5);
- A pieces = **12** (#1031);
- B = **10**;
- C = **8**;
- S does not appear;
- `meta.skipped` = [1040, 1050].

Required tests:
- **Unit / Query**:
  - the example;
  - a removed line ignored;
  - a delivered order counted at `delivered_quantity`;
  - the sort order;
  - the query count fixed for 1 and for 50 orders.
- **«رصيد سابق»** example: the customer owes 1,250.00 in total, this order's remaining is 400.00 → printed **850.00**. Not present for a user without the permissions.
- **HTTP**:
  - happy-path shapes;
  - 0 and 51 / 101 numbers → 422;
  - another tenant's number → 404, the whole call;
  - guest 401;
  - a role without `orders.view` 403;
  - warehouse role gets no prices (D101 f);
  - security sweep.
- **Frontend**:
  - the print page renders lines, nested components and totals, and hides prices with the switch;
  - the bulk bar passes the ticked numbers.
- **e2e**: tick two orders → «كشف تجهيز» → the summed row is on the page.

---

## Slice B — Overdue reminders

### D102 (proposed) — Reminding customers of overdue balances

- **(a) One message per customer, not per order.** It says how much is overdue in total and since when. The amounts come from `OverdueQuery`, so they always match the statement.
- **(b) Store settings** (`tenant_settings`, `settings.manage`):

  | Setting | Values | Default |
  | ------- | ------ | ------- |
  | `overdue_reminders_enabled` | on / off | **off** |
  | `overdue_first_after_days` | days past the oldest overdue order's due date, 1–90 | 3 |
  | `overdue_repeat_days` | days between reminders, 0 = only once, 1–30 | 7 |
  | `overdue_max_reminders` | most reminders while one debt stays overdue, 1–10 | 3 |
  | `overdue_message` | ≤ 500 characters, the store's own words | the platform default below |

  The placeholders are `{اسم_العميل}`, `{اسم_المتجر}`, `{المتأخر}` (overdue amount), `{المستحق}` (total outstanding), `{أيام_التأخير}` (days since the oldest overdue due date) and `{رابط_كشف_الحساب}` (the storefront statement page on the store's host). Any other `{…}` is rejected, like BR-N03.

  Default text: «أهلًا {اسم_العميل}، نذكّرك بمبلغ {المتأخر} ج.م متأخر السداد لدى {اسم_المتجر} منذ {أيام_التأخير} يوم. تقدر تشوف كشف حسابك من هنا: {رابط_كشف_الحساب}».
- **(c) Who gets one.** A customer with overdue > 0 whose oldest overdue order is at least `first_after_days` past its due date, provided:
  - the last reminder was at least `repeat_days` ago (or none yet);
  - fewer than `max_reminders` were sent **since that oldest overdue due date**. When the customer pays it off, the count starts over with the next debt, so no state is kept beyond the send log.

  Customers who opted out of order messages (`order_notifications_opt_out`) get none. The opt-out text becomes «رسائل واتساب عن طلباتي ومستحقاتي». Customers without a phone get none. Blocked customers still get them: they still owe the money.
- **(d) When.** The command `collections:remind-overdue` runs hourly and sends only between **10:00 and 20:00 Cairo**, at most once per customer per day. A store that turns reminders on starts at the next run.
- **(e) Channel and limits.**
  - A new purpose, `payment_reminder`, through `Messenger`: store number → platform number → SMS (D70).
  - Its own per-phone bucket of 3 per day, so it never eats into order messages (D79).
  - Unofficial WhatsApp (D46) is why reminders are off by default, capped and inside working hours.
- **(f) Manual reminder.** «ابعت تذكير» on the customer page (statement), under `collections.manage`:
  - sends the same text now, outside the window and the automatic count, but not to an opted-out customer (`409 customer_opted_out`) or one with nothing overdue (`409 nothing_overdue`);
  - is idempotent and audited `customer.reminder_sent`;
  - at most one manual reminder per customer per hour (`429`).
- **(g) What the merchant sees.**
  - Each reminder is one row in `payment_reminders`: customer, `sent_at`, kind `auto` / `manual`, the overdue amount, the outbound message id, and the sender for a manual one.
  - The customer page lists the last reminders with their delivery status.
  - The outstanding report gets a «آخر تذكير» column.

### BR-C09 — Overdue reminders (new, `02-business-rules.md` §I)

Write D102 (a)–(g) as the rule, with the example in B.3.

### B.1 Backend

- **Migration:** the five `tenant_settings` columns with checks, and `payment_reminders` (tenant-scoped, indexed `(tenant_id, customer_id, sent_at)`).
- **Messaging:**
  - `MessagePurpose::PaymentReminder`;
  - its limit in `config/messaging.php`, next to `order_status_per_phone_per_hour`.
- **Collections:**
  - `Queries\DueRemindersQuery`: the customers due a reminder now, per D102 c, in a fixed number of queries over all customers of the tenant (overdue per customer in SQL, reusing `OverdueQuery`'s conditions, not one call per customer);
  - `Actions\SendPaymentReminder`: renders the text, hands it to `Messenger` after commit, writes the `payment_reminders` row, audits a manual send;
  - `Console\RemindOverdueCommand`: every active tenant, inside the Cairo window, then `SendPaymentReminder` per customer. One customer's failure is logged and does not stop the others.
- **Schedule:** `Schedule::command('collections:remind-overdue')->hourly()->withoutOverlapping();` in `routes/console.php`, plus a line in `deploy/README.md` and `rules/00` production notes (the release checks `schedule:list`).
- **Routes:**
  - `POST /api/dashboard/customers/{customer}/payment-reminders` (`collections.manage`, `idempotent`);
  - `GET` of the same path (the last 20).
- **Settings:** `UpdateSettingsRequest`/`TenantSettingsResource` take the settings, with a `PaymentReminderTemplate` rule like `Tenancy\Rules\OrderStatusTemplate`.
- OpenAPI → types.

### B.2 Frontend

- Settings → «تذكير المستحقات»:
  - the switch, the three numbers and the message, with the placeholder chips and a live preview (like the order-status messages editor);
  - a note: «الرسائل تُرسل من 10 صباحًا إلى 8 مساءً».
- Customer page: «ابعت تذكير», disabled with the reason when nothing is overdue or the customer opted out; the reminders list.
- Outstanding report: the «آخر تذكير» column.
- Storefront account: the opt-out label text (D102 c).

### B.3 Tests

Example for BR-C09, worked out by hand (settings: first 3 days, repeat 7, max 3; today 2026-12-20, 11:00 Cairo):

| Customer | State | Due? |
| -------- | ----- | ---- |
| K1 | oldest overdue due 2026-12-17 (3 days), no reminder | **yes** (= first_after_days) |
| K2 | oldest overdue due 2026-12-18 (2 days) | no |
| K3 | due 2026-12-01, reminders on 12-04 and 12-11 | **yes**: 2 sent < 3, last one 9 days ago ≥ 7 |
| K4 | due 2026-11-20, reminders on 11-23, 11-30 and 12-07 | no: 3 sent = max |
| K5 | due 2026-12-01, reminders on 12-04 and 12-15 | no: last one 5 days ago < 7 |

The full dataset must include:
- `repeat_days` − 1 / = `repeat_days`;
- `max_reminders` reached → no;
- the debt paid and a new one overdue → the count starts over;
- a reminder sent before the current oldest due date not counted;
- opted out → no; blocked → yes; no phone → no;
- 09:59 / 10:00 / 19:59 / 20:00 Cairo (sent at 10:00 through 19:59 only);
- a second run the same day → nothing;
- reminders off → nothing;
- another tenant's customers untouched.

Other required tests:
- **Rendering:** every placeholder with a real amount (money as in the statement), the statement link on the store's host, an unknown placeholder rejected by the settings rule.
- **Command:** one customer's Messenger failure does not stop the next; a rolled-back step sends nothing.
- **HTTP manual:** happy path, audit row, `409 nothing_overdue`, `409 customer_opted_out`, `429` within the hour, same Idempotency-Key → one message, another tenant's customer → 404, permissions matrix, security sweep.
- **Settings validation:** every bound.
- **Frontend:** the settings form and preview, the button's disabled reasons.

### B.4 Manual checks

With `MESSAGING_DRIVER=log`, run `php artisan collections:remind-overdue` against the alnoor demo data (an overdue credit customer exists, Task 17 §3). Record here:
- the `storage/logs/messages.log` line;
- the `payment_reminders` row;
- that a second run sends nothing.

---

## Slice C — Payment methods

### D103 (proposed) — How a payment came in

- **(a) Methods the merchant records:**

  | Code | Label |
  | ---- | ----- |
  | `cash` | نقدي |
  | `instapay` | إنستاباي |
  | `wallet` | محفظة إلكترونية |
  | `bank_transfer` | تحويل بنكي |

  `credit` stays the system's method for paying from a customer credit (BR-C07) and cannot be chosen.

  **Cheques are left out on purpose.** A cheque has a due date and a life (held, deposited, cleared, bounced) that a method code cannot carry. It gets its own task when a merchant asks.
- **(b) Reference.** An optional `reference` (≤ 60, the transfer or wallet transaction number) is kept with the payment.
- **(c) API.**
  - `POST /api/dashboard/orders/{number}/collections` takes `method` (default `cash`, so existing clients keep working; `credit` → 422) and `reference`.
  - Refunds (`RecordRefund`, BR-R04) take the same list.
  - Nothing else changes: amounts, the invariant, voiding.
- **(d) Old rows.** They stay `cash`. That is what was recorded, even if some were transfers in reality; there is no way to know (rules/04 §5).
- **(e) Where it shows:**
  - the collections list (a method filter and column);
  - the order's payments;
  - the customer statement, in the dashboard and on the storefront (method label + reference);
  - the collections report: payments per method instead of the `cash`/`credit` pair. The rows add up to the same total payments as today.
- **(f) No reconciliation.** No wallet or bank accounts, no matching against statements, no gateway. Those are Later (roadmap «Payments»).

### BR-C10 — Payment methods (new, `02-business-rules.md` §I)

Write D103 (a)–(e) as the rule. Update BR-C02 and `07-domain-model.md` §8: the `method` row, the `reference` column, and the CHECK line that still says `method = 'cash'`.

### C.1 Backend

- **Writers first** (rules/04 §3): grep every writer of `collections.method` and list them in the reply. The known ones are `RecordCollection.php:62`, `RecordRefund.php:38`, and the credit actions.
- **Migration** `…_add_collection_payment_methods`: replace `collections_method_known` with the five codes, add `collections.reference varchar(60)` nullable, and a check that `credit` rows have no reference. The `down` restores the two-code check only when no row uses a new code (otherwise it fails with a clear message).
- `CollectionMethod`: the new cases, `label()`, and `recordable()` (the four the merchant may choose).
- `RecordCollectionRequest` / the refund request: `method` in `recordable()`, `reference` nullable ≤ 60. The Data classes and both actions write them; the audit rows carry them.
- **Resources:** the collection, statement-ledger and store-statement resources add `method`, `method_label`, `reference`.
- `ListCollectionsRequest`: an optional `method` filter.
- **Report:** `CollectionsReportQuery` returns `by_method: [{ method, label, amount }]` for payments in the range, every recordable method present (0.00 when unused), plus `credit`. Keep the old `cash`/`credit` keys for one release (marked deprecated in OpenAPI) so the dashboard and report export do not break mid-deploy.
- OpenAPI → types.

### C.2 Frontend

- `RecordCollectionDialog` (and the refund in `ReturnDialog`):
  - a method select (default نقدي);
  - a «رقم العملية» field shown for the non-cash methods, optional.
- Collections list: method column and filter. Order payments and both statements show the label and the reference.
- Collections report: a row per method.

### C.3 Tests

Report example, worked out by hand. In the range:
- payments: cash 1,500.00 and 250.00, InstaPay 800.00, bank transfer 2,000.00, credit 300.00;
- a voided wallet payment of 400.00.

Expected:
- cash 1,750.00;
- instapay 800.00;
- wallet 0.00 (the voided one is not counted);
- bank_transfer 2,000.00;
- credit 300.00.

Total payments 4,850.00, the same as the old `cash` + `credit` (4,550.00 + 300.00) with the new methods folded into cash.

Required tests:
- validation:
  - `method` unknown / `credit` → 422;
  - `reference` 61 characters → 422;
  - no `method` → stored `cash` (old clients);
- the DB check rejects an unknown method and a `credit` row with a reference;
- audit rows carry the method and reference;
- the statement shows them in both surfaces;
- the list filter, and another tenant's collections never listed;
- the permission matrix and the security sweep;
- the migration's `down` refused when a new code is in use;
- frontend: the dialog sends the method and reference, and hides the reference for cash.

---

## Definition of done (each slice)

- `composer test` green, and `npm run typecheck && npm run lint && npm test` green, with the test delta stated (rules/01 §6).
- The `/preflight` table, and the slice's manual checks run with `scripts/smoke.sh` (and the command in B.4), with their results recorded in this file.
- `src-md-files/` updated:
  - `02` (the new BRs and the BR-C02 / BR-N03 wording);
  - `06` (D101–D103 from proposed to final);
  - `07` (`payment_reminders`, the settings columns, `collections.reference` and its check);
  - `08` (the new and changed endpoints).
- The board row updated with the commit.
