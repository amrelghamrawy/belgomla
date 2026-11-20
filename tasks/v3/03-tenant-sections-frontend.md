# V3 Task 03 — Tenant sections in the frontend

Status: **planned**. Requires V3 Task 02. Read `CLAUDE.md`, `rules/*.md` (incl. `03-frontend.md`), then this file.

## Why

The frontend decides fields, labels and icons by `kind` today. After 02 the backend sends `section`; this task moves the frontend over and removes `kind` from the API.

## Today the code does

- `kind` is read in `frontend/lib/dashboard/custom-fields.ts` (`appliesTo(definition, kind)`), `catalog.ts`, `category-tree.ts`, `orders.ts`, `edit-order.ts`, `admin.ts`, copy files `lib/copy/ar.ts`, `ar-dashboard.ts` (section labels, «سعر الغلاف» / «سعر القطاعي»), and the generated `lib/api/schema.d.ts` / `types.ts`.
- The category dialog offers a kind select on roots; the icon fallback (D73) ends on the kind.

## Steps

1. `openapi.yaml`: `Section` schema; `section` on category, product, field definition, price-list row; remove `kind` and the `kind` request fields. `php artisan api:openapi` → `npm run api:types`.
2. Backend: drop the transitional `kind` from resources and requests (task 02 kept it).
3. Frontend: `appliesTo(definition, sectionId)`; reference price label from `section.reference_price_label`; category dialog picks a section from `GET /api/dashboard/sections` (task 07 adds writes; reading can be added here if 07 is not done: the `SectionsQuery` exists since 02); icon fallback uses `section.icon`, then the name guess.
4. Copy: remove hard-coded section names from `lib/copy/*`; names come from the API.

## Tests

- Unit (`npm test`): `appliesTo` with section ids; price label per section.
- E2E: product form shows only the fields of the chosen category's section; changing the category to another section hides them and keeps the values (D69); storefront facets inside a category.

## Definition of done

`npm run typecheck && npm run lint && npm test` and `npm run test:e2e` green; `composer test` green; `/preflight`.
