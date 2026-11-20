# V3 Task 02 — Tenant sections replace `CategoryKind` (backend)

Status: **done** (2026-11-20). Requires V3 Task 01. The riskiest task of the board: **no behaviour change** for existing stores. Read `CLAUDE.md`, `rules/*.md`, then this file.

## Why

D109: a section is tenant data (renamable, hideable, tenant-owned sections), not a fixed enum. Everything that reads `kind` today must read the section.

## Today the code does (rules/04 §2)

- `categories.kind` varchar(20), set on roots, copied to the subtree like `path` (D68, `07-domain-model.md` §categories). `product_field_definitions.kind` nullable (D69).
- `CategoryKind` is used by (backend, 18 files): `CreateCategory`, `UpdateCategory`, `CategoryData`, `FieldDefinitionData`, `StoreCategoryRequest`, `UpdateCategoryRequest`, `StoreFieldRequest`, `UpdateFieldRequest`, `Category`, `ProductFieldDefinition` (`appliesTo()`), `CatalogRowMapper` (`:396`, `:1238-1250`), `CustomFieldValidator` (`:37`, `:58`, `:71`), `VerticalTemplates` (gone in 01), `Pricing\Exceptions\MissingCoverPrice` (label by kind), `Reports\Queries\SalesAnalyticsQuery` (`:135`, sections by kind), plus `FacetQuery.php:69`, `PriceListExporter.php:99`, `CategoryResource.php:30`, `DashboardProductResource.php:130`, `StoreProductResource.php:143,201`, `PriceListRowResource.php:87`, `FieldDefinitionResource.php:28`.
- Factories `CategoryFactory`, `ProductFieldDefinitionFactory`, `StorefrontDemoSeeder`, and ~30 test files use it (`grep -rl CategoryKind backend/tests`).
- The D73 icon fallback ends on the section kind (frontend).

## Schema

- `sections` (tenant-owned, `BelongsToTenant`): `id`, `tenant_id`, `vertical_section_id` FK nullable (null = created by the tenant), `name` varchar(80) unique per tenant (folded, D32), `reference_price_label` varchar(40), `icon` nullable, `is_fallback` bool (exactly one per tenant: «عام», D109 c), `status` (`ArchiveStatus`), `sort_order`, timestamps. Unique (tenant_id, vertical_section_id) where not null.
- `categories.section_id` FK not null (roots and children; copied to the subtree exactly like `kind` today). `categories.kind` dropped.
- `product_field_definitions.section_id` FK nullable (null = every section); `template_id` FK nullable to `vertical_field_templates` (D110 b). `kind` dropped.

## Backfill (per tenant, in the migration)

1. Create the tenant's sections from its vertical's active `vertical_sections` + one fallback «عام» section.
2. `categories.kind` → the section with the matching `vertical_sections.key` (`general` → the fallback). `product_field_definitions.kind` the same; null stays null.
3. Link definitions to templates by key (`template_id`) when the vertical has a template with that key.

## Code

- `Category::section()` / `ProductFieldDefinition::section()`; `appliesTo(Section $section)` = `section_id === null || section_id === $section->id`.
- Requests take `section_id` (exists in the tenant, active; only on roots: `prohibited_unless:parent_id,null`), default the fallback section. Keep accepting `kind` for one release **only if** task 03 does not ship in the same deploy; otherwise drop it (decide at the start of the task, record it here).
- Moving a root to another section re-sections its subtree (same as the D68 "move re-kinds").
- `MissingCoverPrice` and the price list use `section.reference_price_label`; `cover_price` stays optional in every section (D109 d). The only place it is "required" is a price given as a % off it (`Pricing/Exceptions/MissingCoverPrice.php:18`), and that check does not depend on the section, only on the message label. Keep the exact behaviour.
- `SalesAnalyticsQuery` groups by `section_id` with the section name.
- Resources: replace `kind` with `section: { id, name, reference_price_label, icon }`; keep `kind` (the vertical section key, or `general`) in the same payloads until task 03 removes it, so the frontend keeps working between the two commits.
- Provisioning: `ApplyVerticalTemplate` → `ProvisionTenantCatalog`: creates the sections, then the fields (with `section_id`, `template_id`) and options. Idempotent (by `vertical_section_id`, by key, by option label).
- New `Catalog\Queries\SectionsQuery` (list for the tenant) — the CRUD endpoints come in task 07.

## Tests

- Migration test: a tenant with categories and fields of every kind ends with the same visible facets, the same required checks and the same price-list labels (compare before/after on the same fixture).
- Every existing test that used `CategoryKind` is ported, **not deleted**; expected values unchanged (rules/04: worked out from the rule, not from the run).
- New: tenant-owned section on a root category; a field of section A refused for a product of section B (D69 behaviour); moving a root re-sections the subtree; a section of another tenant is 404 (BR-T04).
- Arch: `CategoryKind` no longer exists.

## Definition of done

rules/00 DoD; `composer test` green; smoke on `tenant:demo`: storefront facets inside a books category, product form, price list export identical to before.

## Outcome (2026-11-20)

- Migration `2026_11_20_100100_create_sections.php`: `sections` (with the checks: status known, the fallback active, one fallback per store, one row per vertical section), `categories.section_id` (not null), `product_field_definitions.section_id` / `template_id`; the per-store backfill of the plan (a kind with no such section, and `general`, → the fallback); the `kind` columns and their checks dropped. `down()` writes the keys back into `kind`.
- Catalog: model `Section`, `ProvisionSections` (the vertical's active sections + the fallback, idempotent by `vertical_section_id`, key and name), `SectionResolver` (by `section_id`, else by the old `kind`; `422` unknown / archived on the field that named it), `SectionsQuery`, `SectionResource`. `Category` and `ProductFieldDefinition` eager-load `section` (`$with`); `appliesTo(int $sectionId)`. The observer puts a root without a section in the fallback.
- Every former `CategoryKind` reader moved to the section: create/update category and field, products and bundles, the grade-set generator, `CustomFieldValidator`, `FacetQuery`, `CatalogRowMapper`, the price list (file column = the section name), `MissingCoverPrice` (label = `section.reference_price_label`), the sales report (by section key, labelled with the store's section name). `CategoryKind` deleted (test: the class is gone).
- Test stores get their sections from `TenantFactory` (the same `ProvisionSections` a real store runs); `CategoryFactory::inSection()` / `ProductFieldDefinitionFactory::forSection()` replace `kind()`; `TenantTestCase::sectionId()`.
- OpenAPI: `Section` schema; `section` on `Category`, `DashboardCategory`, `CategoryRef`, `FieldDefinition`, `PriceListRow`; `section_id` on the category and field requests. `schema.d.ts` regenerated, frontend fixtures carry `section` (no app code change).

Decisions taken at the start of the task (recorded in 06 D109 g–i):

- **`kind` is kept in this release** (task 03 ships separately): requests still read it as a section key (`section_id` wins), responses still send it (= `section.key`) next to `section`. The OpenAPI `CategoryKind` keeps its four values until task 03, since no store can make its own section before task 07.
- **`sections.key`** (not in the schema above): the machine name, copied from the vertical section key, `general` for the fallback. Reports, factories and the old `kind` input match on it, never on a name the store can edit. A vertical section must not use `general` (guard for task 06).
- **`ApplyVerticalTemplate` keeps its name** (it now provisions the sections first, then the fields with `section_id` and `template_id`); an existing field of the same key is linked to its template, nothing else of it is touched.

Deviations, on purpose:

- The sales report label of stationery is now «أدوات مكتبية» (the store's section name, D109 e), not «أدوات دراسية»; the price-list file's section column follows the same name.
- The store category tree is two queries (the categories, then their sections in one go), not one.
- `sections.name` is unique per store exactly, not folded (D32): the folded check comes with the section endpoints (task 07), the first place a store names a section.

