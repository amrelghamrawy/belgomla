# V3 Task 02 — Tenant sections replace `CategoryKind` (backend)

Status: **planned**. Requires V3 Task 01. The riskiest task of the board: **no behaviour change** for existing stores. Read `CLAUDE.md`, `rules/*.md`, then this file.

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
