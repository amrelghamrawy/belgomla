# Task 10 — Dashboard UI

Status: **done** (see Delivery notes). Requires Tasks 1–8 (and ideally 9 for shared components). Read `CLAUDE.md`, `rules/*.md` (incl. `03-frontend.md`), then this file.

## Goal

The merchant dashboard, mobile-usable but optimised for tablet/desktop: catalog management with custom fields and bulk tools, pricing, inventory with the BR-INV08 message, customers and invitations, orders workflow, collections, reports, settings, users, audit.

## Read before coding

| What      | Where                                                                          |
| --------- | ------------------------------------------------------------------------------ |
| Product   | `03-v1-v2-roadmap.md` §10 dashboard, `05-module-scope.md` §10                  |
| Rules     | BR-INV08 (exact message/UX), BR-P11/P12, BR-PR10/PR11, BR-C04 shortcut, BR-O01/O02, BR-CU08 |
| Contract  | `08-api-contract.md` §4–§12                                                    |
| Decisions | D5 reports, D9 address edit, D10 stale orders, D19 season rollover tooling      |

## Screens (App Router, `app/(dashboard)/dashboard`)

1. Login, layout with RTL sidebar, tenant name/logo, notifications bell (unread count polling 30 s), user menu.
2. Overview: cards from `reports/overview`, stale pending orders list, low-stock list.
3. Catalog
   - Categories tree with drag-reorder and move (cycle error surfaced), archive/restore.
   - Units list.
   - Custom fields: definitions table, options editor, filter order, type locked warning.
   - Products list with the same facets as the store plus status/low-stock filters, bulk select → bulk status change and bulk pricing (preview step showing affected/skipped), duplicate action.
   - Product form: tabs (basics, custom fields rendered from definitions, images with reorder, pricing with percent-or-price toggle and tiers editor, relations picker, opening stock on create only).
4. Inventory: table with on hand / reserved / available / min allowed; adjust drawer showing "أقل كمية ممكنة" and, on 422, the holding orders as links; movements history per SKU; bulk adjust CSV-less table paste? **No** (V2) — simple multi-row form.
5. Customers: list with status + outstanding, create + invite (shows sent channel), approve/block, detail with addresses, orders, statement.
6. Orders: list with status tabs, collection status, stale badge, filters; detail with timeline, items, delivery address edit (before out for delivery), transition buttons that only show allowed next steps (from `OrderStatus` rules mirrored in a TS enum), "تسليم وتحصيل كامل" shortcut, cancel with reason and the has-collections block message.
7. Collections: list with filters, record collection on order page, void with reason.
8. Reports: overview, sales (day/week/month chart — load `dataviz` skill before charting), outstanding per customer, low stock; export none (V2).
9. Settings: branding (colors with preview, logo/favicon upload), contact, working hours editor, MOV toggle+amount, price visibility and approval toggles; Users: list/create/disable with the self/last-user errors surfaced.
10. Audit: filterable table (entity, actor, dates, batch) with old/new diff view.

## Tests

- Vitest: product form custom-field renderer from definitions, tiers editor validation (duplicate threshold), percent↔price conversion display, allowed transitions helper, BR-INV08 error renderer.
- Playwright: login → create category/unit/product with custom fields → see it on the store; adjust stock below reserved after a checkout → error with order links; order confirm → deliver + collect → collection visible; bulk pricing preview → apply.

## Definition of done

All screens wired to the real API, no mocked data; `npm run typecheck && lint && test` green; e2e recorded; mark done.

## Open questions

- D42: Dashboard default landing (overview vs orders). Default: overview.
- D43: Table pagination size. Default: 24 (matches `PaginatedRequest`).

## Delivery notes (commits 8ecc780 spec + shell, 7eccbe9 catalog, 3657316 inventory, aa7ddfc customers, 16ae1c3 orders + collections, 328ffe4 reports, a41a980 settings/users/audit, then the e2e commit)

Delivered as vertical slices, one commit per area with its tests (agreed with the product owner at the start).

**Results (2026-10-10):**
- Backend `composer test` is green: 2707 tests (2664 passed + 43 arch tests flagged deprecated by PHP 8.5), +13 over Task 9. Type coverage 100 %. No backend behaviour changed; the additions are spec and tests.
- Frontend `typecheck`, `lint` and `build` are green; 423 Vitest tests pass (+120 over Task 9).
- 22 Playwright tests pass (11 per project, mobile 390×844 and desktop 1280×800) against `php artisan serve` + `next dev` on the seeded dev database (alnoor + StorefrontDemoSeeder, plus data left by earlier runs; it was not wiped). New in `tests/e2e/dashboard.spec.ts`:
  - login → category → unit → select field with options → product with custom field, cover/base price and opening stock → visible in the store search → bulk pricing preview (1 affected) → apply → new price in the list;
  - a shopper (own browser context, through the store API) orders → adjust stock to 0 → BR-INV08 refusal with the order linked → open it → confirm → «تسليم وتحصيل كامل» → delivered + collected, balance 0 → collection in the list;
  - every dashboard screen and an order page have no horizontal scroll on either project (this test found the order page overflowing on phones; fixed).
- The live calls in the e2e runs went through `php artisan serve` (session, CSRF, tenant host, middleware order), which stands in for the curl smoke: the backend itself did not change.
- Every screen was looked at on both projects (screenshots), the sales chart with sample buckets: RTL time axis, value axis on the right, tooltip in text tokens.
- Rule ids covered by tests: BR-INV06, BR-INV08, BR-P11, BR-P12, BR-PR04, BR-PR10, BR-PR11, BR-O01, BR-O02, BR-O03, BR-C04, BR-CU05, BR-CU08, D5, D9, D10, D27, D29, D30, D36, D42, D43, D54, D55, D58–D60.

What differs from the plan above, and why:
- **Backend spec:** only 3 dashboard operations were in the OpenAPI spec, so all 66 others were added (schemas from the Resources). `OpenApiSpecTest` now also checks that every dashboard route is documented, and `OpenApiDashboardTest` checks real responses against the schemas.
- **Bulk select → bulk by filter:** the bulk endpoints take a filter, not ids (BR-P12 "by filter"), so bulk status and bulk pricing apply to every product matching the current list filters. They are blocked for an empty filter, and for the low-stock filter, which the bulk filter does not support (it would reach products the list hides).
- **Category move:** drag reorders within one sibling list; moving to another parent is the "parent" picker in the edit dialog, which hides the node's own subtree. `category_cycle` from the backend is still shown.
- **Customer orders tab:** the 5 recent orders from the detail plus a link to the orders list filtered by customer, not a second paginated list.
- **Movements:** a movement carries `order_id`, not the order number, so movements are not linked to orders.
- **Notifications:** the bell shows the 10 newest; there is no separate notifications page.
- **Copy:** dashboard strings live in `lib/copy/ar-dashboard.ts`, mounted as `ar.dashboard` (rules/03 updated).
- **Lint:** the physical-class rule exempts `slide-in-from-left/right` / `slide-out-to-left/right`, which shadcn keys off Radix's physical `data-[side]`.
- **Charts and drag:** recharts and dnd-kit were added (agreed with the product owner).
- **Open decisions:** D42 and D43 were added to 06 with their defaults.
