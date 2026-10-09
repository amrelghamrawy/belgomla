# Task 10 — Dashboard UI

Status: **not started**. Requires Tasks 1–8 (and ideally 9 for shared components). Read `CLAUDE.md`, `rules/*.md` (incl. `03-frontend.md`), then this file.

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
