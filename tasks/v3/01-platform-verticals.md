# V3 Task 01 — Platform verticals, sections and field templates

Status: **done** (2026-11-20). Requires V3 Task 00. Read `CLAUDE.md`, `rules/*.md` (incl. `04-business-truth.md`), then this file.

## Why

The vertical and its field templates are code today. A new vertical (ملابس) or a new default field would need a deploy. D108/D110 make them platform data that the admin edits (task 06).

## Today the code does (rules/04 §2)

- `Tenancy\Enums\Vertical` (`backend/app/Modules/Tenancy/Enums/Vertical.php`): `school-books`, `stationery`, `hardware`, `none`, cast on `tenants.vertical` (`Tenant.php:75`), carried by `NewTenantData::$vertical`, validated by `InvalidTenantInput::vertical()`, shown by `TenantResource.php:27`, `TenantListCommand`, `TenantOverviewQuery`.
- `tenant:create --vertical=` (`TenantCreateCommand.php:25,40`) and `tenant:demo` (`TenantDemoCommand.php:47`, `school-books`), `DatabaseSeeder.php:30` (`SchoolBooks`, «مكتبة النور»).
- Templates are PHP files `backend/database/verticals/{school-books,stationery,hardware}.php`, read by `Catalog\Services\VerticalTemplates` and copied into the new tenant by `Catalog\Actions\ApplyVerticalTemplate` (idempotent by key / option label), registered on `TenantCreatedHooks` from `CatalogServiceProvider`.
  - `school-books.php`: grade, subject, publisher, term, track, edition_type, academic_year, all `kind: books`.
  - `stationery.php`: brand, color, size, `kind: stationery`.
  - `hardware.php`: brand, material, size, no kind (every section).
- Sections are `Catalog\Enums\CategoryKind` (`books` كتب خارجية, `stationery` أدوات دراسية, `toys` ألعاب, `general` عام). Task 02 replaces it; this task only creates the platform rows it will map to.

## Schema (platform tables: no `tenant_id`, no `BelongsToTenant`, rules/02 §models)

- `verticals`: `id`, `key` varchar(40) unique (slug, immutable after create), `name` varchar(80), `status` (`active` | `archived`), `sort_order`, timestamps.
- `vertical_sections`: `id`, `vertical_id` FK, `key` varchar(40) (unique per vertical, immutable), `name` varchar(80), `reference_price_label` varchar(40) (default «سعر القطاعي»), `icon` nullable (the `CategoryIcon` set, D73), `status`, `sort_order`, timestamps.
- `vertical_field_templates`: `id`, `vertical_id` FK, `vertical_section_id` FK nullable (null = every section of the vertical), `key` varchar(40) (unique per vertical; same key rules as `product_field_definitions.key`), `label`, `type` (`FieldType`), `is_required`, `is_filterable`, `filter_order`, `sort_order`, `status`, timestamps.
- `vertical_field_template_options`: `id`, `template_id` FK, `label`, `sort_order`, unique (template_id, label).
- `tenants.vertical_id` FK **not null** (D108); the old `tenants.vertical` column is dropped in the same migration after the backfill.

Models in the Platform module (`Platform\Models\Vertical`, `VerticalSection`, `VerticalFieldTemplate`, `VerticalFieldTemplateOption`). Other modules read them only through `Platform\Queries\VerticalTemplateQuery` (returns DTOs, rules/02 cross-module rule).

## Data migration (one migration, reversible on a fresh DB, one-way on data)

1. Insert vertical `libraries` «مكتبات» with sections `books` «كتب خارجية» (reference label «سعر الغلاف», icon books), `stationery` «أدوات مكتبية» (D109 e), `toys` «ألعاب», all active.
2. Insert the templates of the three PHP files under `libraries`: school-books fields → section `books`; stationery fields → section `stationery`; hardware fields that do not collide by key (`material`) → no section. `brand` and `size` exist in both stationery and hardware: keep the stationery ones (section `stationery`) and record that hardware tenants' copies are unaffected (they are tenant rows already).
3. Every tenant (`school-books`, `stationery`, `hardware`, `none`) → `vertical_id = libraries` (D108 c).
4. Delete `backend/database/verticals/`, `VerticalTemplates`, `Vertical` enum.

## Code

- `NewTenantData::$vertical` becomes `string $verticalKey`; `CreateTenant` resolves it through `VerticalTemplateQuery::activeByKey()` (unknown / archived → `InvalidTenantInput::vertical()` listing the active keys).
- `tenant:create --vertical=libraries` (default `libraries` while it is the only vertical; the help text lists the keys from the DB). `tenant:demo` and `DatabaseSeeder` use `libraries`.
- `ApplyVerticalTemplate` keeps its behaviour for now (fields only, from DB templates instead of files, `kind` mapped from the section key); task 02 makes it copy sections too.
- `TenantResource` / `TenantOverviewQuery` / `tenant:list`: `vertical` → `{ key, name }`.

## Tests (rules/01)

- Migration test on a DB seeded with one tenant per old vertical value: all end on `libraries`, templates rows match the three files (counts, keys, option labels, section of each).
- `ApplyVerticalTemplate`: a new tenant gets exactly the same definitions and options it got before (snapshot of keys/options/kinds per old vertical), idempotent on re-run.
- `tenant:create` with an unknown and an archived vertical → 422 `invalid_vertical`.
- Arch: the new Platform models are not tenant-scoped; Catalog/Tenancy do not import `Platform\Models`.

## Definition of done

rules/00 DoD; `composer test` green; smoke `tenant:create` + `tenant:list` on `php artisan serve`.

## Outcome (2026-11-20)

- Migration `2026_11_20_100000_create_verticals.php` creates the four platform tables, seeds «مكتبات» (sections books «كتب خارجية» / stationery «أدوات مكتبية» / toys «ألعاب», icons `book-open` / `pencil` / `puzzle` as the frontend's D73 fallback by kind) and the 11 templates, moves every tenant to it and drops `tenants.vertical`. `down()` restores the column as `none` (schema only).
- Platform module: models `Vertical`, `VerticalSection`, `VerticalFieldTemplate`, `VerticalFieldTemplateOption`, enum `VerticalStatus`, DTOs `VerticalData` / `FieldTemplateData`, `VerticalTemplateQuery` (`activeByKey`, `activeKeys`, `find`, `findMany`, `fieldsFor`). Arch: Platform models used only in Platform (+ factories/seeders), not tenant-scoped; Tenancy and Catalog never import them.
- `TenantResource` takes the `VerticalData` from the controller (`MeController`), since Tenancy may not read Platform models: `/api/dashboard/me` → `tenant.vertical: { key, name }` (openapi + `schema.d.ts` regenerated, frontend fixture updated).
- Removed: `Tenancy\Enums\Vertical`, `Catalog\Services\VerticalTemplates` (+ its test), `database/verticals/` (+ its PHPStan path).

Deviations from the plan above, on purpose:

- **"A new tenant gets exactly the same definitions as before, per old vertical" cannot hold:** with one vertical, every new store gets the union of the three files (7 books + brand/color/size + material = 11 fields). Before, `tenant:create` defaulted to `none` (0 fields) and `school-books` gave 7. The tests assert the union, hand-written from BR-P09. The demo store has 12 fields (11 + `age`); `material` has no options, so it is not a facet anywhere (`/api/store/filters` in a toys category is still `['age']`).
- **`tenant:create --help` does not list the keys from the DB**: the signature is built when artisan boots, and a query there would run on every command and fail on a fresh DB. An unknown or archived key fails with `Vertical must be one of: <active keys>`.
- A template whose section key is not a `CategoryKind` value gets `kind = null` (every section) until Task 02 replaces kinds with tenant sections. Only future verticals can have such keys.
