# V2 Task 21 — Reorder, shared-link previews, return reasons

Status: **planned**. Three small, independent slices. The ideas come from the ILORA e-commerce module (`D:\algoriza\ilora\backend\internal\modules\ecommerce`: `order_duplicate.go`, `storefront_og.go`, `return_reasons.go`), cut down to what a wholesale store uses. Requires V2 Tasks 01 (returns), 13 (selling units), 15 (bundles), 16 (reports) and 18 slice B (the adjustment-reason pattern this task copies). Read `CLAUDE.md`, `rules/*.md` (incl. `04-business-truth.md`), then this file.

## Why

- **Reorder.** A wholesale customer buys the same titles again and again (each term, each restock). Today they rebuild the cart line by line from memory or from an old order's page.
- **Link previews.** Merchants share product and category links in WhatsApp groups. Today every link previews as the platform name with no picture, because the storefront's only metadata is the platform's (`frontend/app/layout.tsx:18`) and the product page renders on the client (`frontend/app/(store)/p/[slug]/page.tsx`).
- **Return reasons.** End-of-season returns are the norm (D20). Today the reason is free text (`RecordReturnRequest.php:24`, stored as-is at `RecordReturn.php:143`), so nobody can tell how much came back damaged, as a wrong edition, or as unsold season stock.

## Slices

| Slice | What | Decision | Rule |
| ----- | ---- | -------- | ---- |
| A | «اطلب تاني»: the customer puts the lines of a past order back in the cart, at today's prices | D104 (proposed) | BR-CU09 (new) |
| B | Fixed reasons for returns, and a returns-by-reason report | D105 (proposed) | BR-R07 (new) |
| C | Store and product link previews (Open Graph) for WhatsApp and other apps | D106 (proposed) | BR-T08 (new) |

The slices can ship in any order, each in its own commit. Copy D104–D106 into `src-md-files/06-open-decisions.md` with their defaults in the first slice you do (rules/00). Add each new BR to `02-business-rules.md` in its own slice.

## Today the code does (rules/04 §2)

- **Cart.** A line is put in the cart by `Customers\Actions\AddCartItem::handle` (`backend/app/Modules/Customers/Actions/AddCartItem.php:32`). It upserts by `(cart, sku_unit, kind)` and **replaces** the quantity (`:38`). It first checks the line with `OrderableQuantity::assert` (`backend/app/Modules/Customers/Services/OrderableQuantity.php:29`), which refuses with `CartLineInvalid`:
  - `productInactive`: product, SKU or unit not active (`:37-38`);
  - `insufficientStock`: carries the available quantity in the unit (`:52`);
  - `belowMoq` (`:94`);
  - `preorderUnavailable` (`:67`).

  Bundles are checked apart (`:74-87`). The cart stores no prices; they are resolved on read (BR-PR06, `07-domain-model.md` §6 carts).
- **Order lines.** Order lines keep `sku_unit_id`, nullable but backfilled to the default unit (Task 13). Removed lines keep `removed_at` (Task 09). Bundle component lines have a `parent_item_id`, and the bundle line has `is_bundle` (Task 15). The store order resource exposes `sku_unit_id` and `parent_item_id` (`backend/app/Modules/Orders/Http/Resources/OrderItemResource.php:36,55`).
- **Store order routes.** They need an active customer (`backend/routes/api/store/orders.php`). Checkout uses the `idempotent` middleware.
- **Returns.** Returns are recorded only from the dashboard (`POST /api/dashboard/orders/{number}/returns`, `orders.returns`). `reason` is required, at most 300 characters, and trimmed (`RecordReturnRequest.php:24,35-37`). It is copied to `order_returns.reason` (`RecordReturn.php:143`). The customer never sees it (BR-R05, `02-business-rules.md:746`). The product report already counts returns per SKU (`backend/app/Modules/Reports/Queries/ProductPerformanceQuery.php:20-29`).
- **Server-side storefront reads.** Server components read storefront data only through `readStoreJson` (`frontend/lib/server/store-data.ts`, rules/03 §4). It forwards the shopper's Host and cookies, so D1 hidden prices hold. On any failure it returns null.
- **Store settings for the storefront.** `GET /api/store/tenant` returns `TenantSettingsResource` (`backend/app/Modules/Tenancy/Http/Controllers/Store/TenantController.php:13`), with `name`, `logo_url`, `favicon_url`, `description` and `require_login_for_prices` (`TenantSettingsResource.php:29-41`). `GET /api/store/products/{slug}` returns `name`, `description`, `status`, `category` and `gallery` (`StoreProductResource.php:137-155`).

Not checked: whether Next 16 with `cacheComponents: true` lets a page's `generateMetadata` read `headers()` without making the page shell dynamic, and whether it streams metadata into `<body>` for crawlers. Slice C verifies this first (see C.4).

---

## Slice A — «اطلب تاني» (reorder)

### D104 (proposed) — Reorder

- **(a) Who and which orders.** An active customer may reorder any of their own orders, in any status, including cancelled (often the one they most want back) and pre-orders. Another customer's order is `404` (OrderPolicy). Another tenant's order is `404` (BR-T04).
- **(b) What is copied.** Only the order's top-level, non-removed lines:
  - loose lines, in the line's `sku_unit_id` (null → the SKU's default unit);
  - bundle lines, as the bundle's unit.

  Component lines (`parent_item_id` set) and removed lines (`removed_at` set) are skipped. The quantity is the line's **ordered** `quantity` in that unit, not the delivered one.
- **(c) Kind.** Every line is added as an `order` line. A product that is now Coming Soon with pre-orders on is added as a `preorder` line instead (BR-O14), so a pre-order can be repeated. Anything else not orderable now is skipped.
- **(d) Prices.** Never copied. The cart prices on read as usual (BR-PR06, BR-PR14), so the customer sees today's price, group, promotion and tiers. The old order's manual discount (BR-PR08) does not carry over.
- **(e) Merging with the cart.** A unit already in the cart **adds up**: the new quantity is the cart's plus the order's. This is unlike `AddCartItem`, which replaces. The sum is checked as one line.
- **(f) Best effort, line by line.** The call never fails as a whole because of one line. Each line ends up as one of:
  - `added`;
  - `reduced`: stock covers less than asked; the available quantity in that unit is added if it is at least the MOQ;
  - `skipped`, with a code: `inactive`, `deleted`, `out_of_stock`, `below_moq`.

  Orders with more than 200 top-level lines are not expected. Cap it at 200 and refuse above with `422`.
- **(g) Twice.** The route uses `idempotent` (like checkout), so a double tap does not double the cart.
- **(h) Out of scope.** No merchant-side reorder (prefilling the dashboard's new-order form, Task 07), no audit row (cart changes are not audited), no "reorder" notification.

### BR-CU09 — Reorder (new, `02-business-rules.md` §J)

Write D104 (a)–(f) as the rule, with the example in A.3.

### A.1 Backend

- `POST /api/store/orders/{number}/reorder` in `backend/routes/api/store/orders.php`, with the active-customer middleware group and `idempotent`.
- Authorize with `OrderPolicy::reorder` (own order only).
- Response `200`:

  ```
  { data: { cart: <the cart resource>, lines: [ { order_item_id, sku_unit_id, product_name, unit_name, asked, added, outcome: added|reduced|skipped, reason: null|inactive|deleted|out_of_stock|below_moq } ] } }
  ```

- Class placement follows the module boundary test (`tests/Arch/ModulesBoundariesTest.php`):
  - Orders reads the lines: `Orders\Queries\ReorderLinesQuery`, which returns `(order_item_id, sku_unit_id|null, sku_id, quantity, is_bundle)`.
  - Customers writes the cart: `Customers\Actions\AddLinesToCart`. It takes a list of `CartLineData`, has merge-not-replace semantics, and returns the outcome per line. Inside **one** transaction it:
    1. locks the cart;
    2. reuses `OrderableQuantity` per line, catching `CartLineInvalid` to classify the line;
    3. retries `insufficientStock` once at its available quantity.
- `AddCartItem` stays as it is (single line, replace).
- OpenAPI: the new path and schema. Then `php artisan api:openapi` → `npm run api:types`.

### A.2 Frontend

- An «اطلب تاني» button:
  - on the order page (`components/store/OrderDetailView.tsx`), for every order;
  - in the orders list (`OrdersList.tsx`), as a small action per row.
- On success, write the returned cart with `setQueryData` (rules/03 §4) and go to `/cart`. The cart shows a dismissable summary when anything was reduced or skipped, for example «تمت إضافة ١٢ صنفًا — ٢ بكمية أقل لعدم توفر المخزون — ١ غير متاح الآن». It is expandable to the lines with their reasons in Arabic.
- One line under the button: «الأسعار حسب أسعار اليوم» (D104 d).
- Arabic copy in `lib/copy/ar.ts`. The Idempotency-Key is made the same way as checkout's.

### A.3 Tests (expected values worked out from D104 before running)

Example for BR-CU09:
- the order had 5 cartons of A (MOQ 2), 10 pieces of B and a bundle S × 1;
- the cart already has 3 cartons of A;
- stock covers 6 cartons of A, B is now `draft`, and S is fully available.

Expected:
- A → 3 + 5 = 8 asked, 6 available → `reduced`, cart A = 6;
- B → `skipped`, `inactive`;
- S → `added` 1.

Required tests:
- **Unit**:
  - `AddLinesToCart` with a dataset, at the boundaries:
    - available = asked → `added`;
    - available = asked − 1 → `reduced`;
    - available < MOQ → `skipped`, `below_moq`/`out_of_stock`;
    - MOQ − 1 after reduction.
  - The merge (existing + ordered).
  - Pre-order kind (D104 c).
  - Removed and component lines ignored.
  - A failing line leaves the others in place.
  - A throwing step rolls everything back.
- **HTTP**:
  - happy path shape (A.1);
  - another customer's order → 404;
  - another tenant's → 404;
  - guest → 401;
  - blocked customer → 403;
  - cross-host session;
  - the same Idempotency-Key twice → one addition;
  - more than 200 lines → 422;
  - security sweep dataset.
- **Frontend** (vitest):
  - the button posts with an Idempotency-Key;
  - the cart summary renders reduced and skipped lines.
- **e2e** (`npm run test:e2e`): order → cancel → «اطلب تاني» → cart has the lines.

---

## Slice B — Return reasons

### D105 (proposed) — Return reasons

- **(a) Fixed list** (`order_returns.reason_code`, like D97 for adjustments):

  | Code | Label |
  | ---- | ----- |
  | `season_end` | مرتجع نهاية الموسم |
  | `damaged` | تالف أو عيب طباعة |
  | `wrong_item` | صنف أو طبعة غير المطلوبة |
  | `excess` | زيادة عن الحاجة |
  | `curriculum_changed` | تغيير المنهج أو الطبعة |
  | `other` | أخرى |

  The list is the same for every vertical. A merchant-defined list (as ILORA has per store) is left for later, when a merchant asks.
- **(b) API.** `RecordReturnRequest` takes:
  - `reason`: one of the codes (an unknown code → `422 validation_failed` on `reason`);
  - `note`: optional, ≤ 250, **required** for `other`.

  `order_returns.reason` keeps the readable text: the label, then «: note» when there is one (the D97 `text()` shape). It is always ≤ 300. This is a breaking change for the dashboard only. No external client sends returns.
- **(c) Old rows.** Returns recorded before this keep their text and have `reason_code` null. No backfill: free text cannot be mapped to a code safely (rules/04 §5). Reports show them as «غير محدد».
- **(d) Visibility.** Unchanged. The customer still never sees the reason (BR-R05). Voiding a return keeps its free-text `void_reason`.
- **(e) Report.** Returns by reason over the live returns (not voided) in a date range by `returned_at` (Cairo days, D60), one row per code plus the null row:
  - `returns` (count);
  - `returned_amount` (Σ return totals, money string);
  - `returned_base` (Σ base units);
  - `share` (% of the range's returned amount, 2 decimals, half-up).

  The rows add up to the range's total returns, which is the same figure the sales report uses (BR-R05).

### BR-R07 — Return reasons (new, `02-business-rules.md` §H2)

Write D105 (a)–(d) as the rule.

### B.1 Backend

- `Orders\Enums\ReturnReason` (codes + `label()` + `toArray()`).
- Migration `…_add_return_reason_codes`:
  - `order_returns.reason_code varchar(20)` nullable;
  - a check that it is one of the codes;
  - idempotent `up`/`down`.
- `RecordReturnData` gets `reasonCode` and `note`. `RecordReturn` writes both columns; the audit row carries the code and the note.
- Writers of `reason_code` (rules/04 §3): only `RecordReturn`. Grep it and list the writers in the reply.
- `OrderReturnResource` (dashboard) adds `reason_code`.
- `GET /api/dashboard/reports/returns-by-reason?from&to`:
  - permission `reports.view`;
  - Query `Reports\Queries\ReturnsByReasonQuery`;
  - exportable to XLSX/CSV through the same exporter as the other D94 reports.
- The product report (`/reports/products`) accepts an optional `return_reason` filter that narrows its `returns`/`returned_base`/`return_rate` to that reason. Leave this out if it makes the query noticeably heavier, and say so in the task.
- Seeder: the demo returns get codes (`DemoActivitySeeder`, through actions only).
- OpenAPI → types.

### B.2 Frontend

- The record-return form: a reason select + a note field («اختر السبب» / «اكتب التفاصيل»). Reuse `ReasonFields` from Task 18 slice B if it generalises cleanly; otherwise copy its shape.
- The returns list and the order's returns show the label (old rows: their text).
- Reports: a «المرتجعات حسب السبب» tab or card with the table, a bar per reason, and export.

### B.3 Tests

Example for D105 e:
- in the range, three live returns: `damaged` 120.00 / 6 base, `season_end` 480.00 / 40 base, and an old one with no code 200.00 / 10 base;
- one voided `damaged` return of 50.00 is not counted.

Expected rows:
- `season_end` 480.00, 60.00 %;
- `unspecified` 200.00, 25.00 %;
- `damaged` 120.00, 15.00 %.

Total 800.00.

Required tests:
- validation for every rule: unknown code; `other` without a note; a note of 251 characters; `import`-style codes not in the list;
- the stored text for a code with and without a note;
- audit row;
- a migration check that rejects an unknown code at the DB level;
- the report:
  - the example above;
  - a return on the range's last Cairo day at 23:59 included, and 00:00 the next day excluded;
  - another tenant's returns never counted;
- the permission matrix;
- the security sweep;
- frontend: the form sends the code and note; «أخرى» needs a note; the report renders and exports.

---

## Slice C — Link previews (Open Graph)

### D106 (proposed) — What a shared link shows

- **(a) Pages.**

  | Page | `og:title` | `og:description` | `og:image` | `og:type` |
  | ---- | ---------- | ---------------- | ---------- | --------- |
  | Store home, and every store page without its own | store name | store description | store logo | `website` |
  | Product (`/p/{slug}`) | «{product} \| {store}» | the product description, plain text, cut at 200 characters on a word | the first gallery image, else the store logo | `product` |
  | Category (`/c/{slug}`) | «{category} \| {store}» | store description | store logo | `website` |

  `<title>` and `description` follow the same values. Every page also sends:
  - `og:site_name` = store name;
  - `og:locale` = `ar_EG`;
  - `og:url` = the canonical absolute URL on the store's host;
  - `twitter:card` = `summary_large_image` when there is an image.
- **(b) Never a price.** Not even when the store shows prices to guests. Prices differ per customer, group, tier and promotion (BR-PR14). A preview is cached by WhatsApp for days, so it would go stale. Stock is never shown either.
- **(c) Hidden or unknown products.** A product the storefront would not show (draft, archived, another tenant, unknown slug) gets the store's preview. The 404 page itself is unchanged.
- **(d) Images.** These are absolute URLs on the store host (`https://{host}/storage/...`, D61), not `next/image` URLs, which crawlers may not fetch. A store without a logo and a product without an image send no `og:image`. There is no generated cover (`GeneratedCover` is client-only).
- **(e) Dashboard.** Out of scope. The dashboard keeps the platform title and gets `robots: noindex` in the same change, since it costs one line.
- **(f) Favicon.** The store's `favicon_url`, when set, replaces the platform icon on storefront pages.

### BR-T08 — Shared links show the store (new, `02-business-rules.md` §A)

A storefront link shared outside the store (WhatsApp, Facebook, Telegram) previews with the store's own name, description and picture, never the platform's and never a price (D106).

### C.1 Frontend

- `lib/server/store-metadata.ts`:
  - reads `/api/store/tenant` and, for a product, `/api/store/products/{slug}`, through `readStoreJson`;
  - builds the result with a pure `storeMetadata({ store, product?, category?, host })` that returns a Next `Metadata`. It holds all of D106 (a)–(d), so it is unit-tested without a server;
  - on any read failure returns the store-only metadata, or the platform's when even the store cannot be read.
- `generateMetadata` in `app/(store)/layout.tsx` (store defaults), `app/(store)/p/[slug]/page.tsx` and `app/(store)/c/[slug]/page.tsx`. The category name comes from the category tree the listing already reads.
- `app/(dashboard)`: `robots: { index: false }`.
- No backend change is expected. If `products/{slug}` cannot answer the crawler (no cookies) because of D1, check `StoreProductResource` first: with hidden prices it must still return the name, description and gallery. If it does not, the fix is in the backend and is recorded in the task.

### C.2 Copy

The description cut-off uses an ellipsis «…». No new Arabic strings except the platform fallback, which already exists (`ar.appName`, `ar.appDescription`).

### C.3 Tests

- **vitest**, `storeMetadata` with a dataset:
  - product with a gallery;
  - without → logo;
  - without either → no `og:image`;
  - a description of exactly 200 characters kept whole;
  - 201 characters cut on a word with «…»;
  - HTML/markdown in the description stripped;
  - the absolute image URL on the given host;
  - `og:url` canonical (no query string);
  - **no price field anywhere in the output**, even when the product data carries prices (D106 b).
- **Fallbacks**: the product read fails → store metadata; the tenant read fails → platform metadata.

### C.4 Manual checks (record in this file when delivered)

First, before writing code, confirm the open question from "Today the code does":
- does `generateMetadata` with `headers()` work under `cacheComponents` without breaking the static shell or Lighthouse (tasks/9: mobile ≥ 90)?
- do crawlers get the tags in `<head>`?

Next's streamed metadata is only blocking for user agents in `htmlLimitedBots`, and WhatsApp's agent must be in it. Record what you found here.

Then, against `npm run build && npm start` on `alnoor.gomal.test`:

| Call | Expected |
| ---- | -------- |
| `curl -A "WhatsApp/2.23.20.0 A" http://alnoor.gomal.test:3000/p/<slug>` | `<head>` has `og:title` «{product} \| مكتبة النور», the gallery image as an absolute URL, no price |
| the same for a draft product's slug | the store's preview |
| `curl -A "facebookexternalhit/1.1" …/c/<slug>` | the category title |
| `curl …/dashboard/login` | `<meta name="robots" content="noindex…">` |
| `curl -I` on the `og:image` URL | `200` with an image content type |

Last step, once on the production VM after deploy: paste a product link in a WhatsApp chat and check the preview card.

---

## Definition of done (each slice)

- `composer test` green, and `npm run typecheck && npm run lint && npm test` green, with the test delta stated (rules/01 §6).
- `/preflight` table, plus the slice's manual checks run with `scripts/smoke.sh` (A, B) or `curl` (C), and their results recorded in this file.
- `src-md-files/` updated:
  - `02` (the new BR);
  - `06` (D104–D106 from proposed to final);
  - `07` (`order_returns.reason_code`);
  - `08` (the reorder and report endpoints, and the changed return request).
- The board row updated with the commit.
