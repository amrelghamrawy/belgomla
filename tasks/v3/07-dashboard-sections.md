# V3 Task 07 — Merchant dashboard: sections management

Status: **planned**. Requires V3 Tasks 03 and 06. Read `CLAUDE.md`, `rules/*.md` (incl. `03-frontend.md`), then this file.

## Why

D109/D110: each store uses what it wants of its vertical's defaults, edits it, and adds its own sections and fields.

## Today the code does

- Custom fields CRUD: `routes/api/dashboard/catalog.php:47-50,85` (`fields`, `fields/{field}/archive`, options), read with `orders.view`, write with `catalog.manage` (D82).
- Sections are read-only (`SectionsQuery`, task 02).

## Endpoints (`/api/dashboard`, `catalog.manage` for writes, `orders.view` for reads)

- `GET /sections` (active + archived, with categories and fields counts).
- `POST /sections` (tenant-owned: name, reference price label, icon), `PUT /sections/{id}`, `POST /sections/reorder`.
- `POST /sections/{id}/archive`: D109 f: refused while an active root category uses it; the fallback section cannot be archived. `POST /sections/{id}/restore`.
- `GET /sections/defaults`: the vertical's active sections and field templates the store does not have (or archived), so it can take them; `POST /sections/defaults` with the chosen template ids → `ApplyVerticalDefaults` limited to those ids.

## Frontend

- Settings → «الأقسام»: list with drag reorder, rename, icon, reference price label, hide/show, «قسم جديد».
- «إضافة من القوالب»: the missing defaults grouped by section, with checkboxes.
- Custom fields page grouped by section (and «كل الأقسام»), the section select in the field dialog lists the tenant's sections.
- Copy in `lib/copy/ar-dashboard.ts`; D104 dashboard identity.

## Tests

- Backend: archive refused with an active root category; fallback cannot be archived; taking defaults adds exactly the chosen ones; tenant isolation (another tenant's section 404).
- Frontend unit + e2e: add an own section, create a root category in it, add a field to it, see the field on a product of that category only.

## Definition of done

rules/00 DoD; `npm run test:e2e`; `/preflight`.
