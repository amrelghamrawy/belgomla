# Task 1 — Catalog module

Status: **not started**. Execute in its own session. Read `CLAUDE.md` and `rules/*.md` first; they are binding.

## Goal

Merchants manage categories, units, custom fields, products (each with exactly one SKU in V1), images and product relations through the dashboard; the storefront lists and filters products. No pricing tiers, no inventory, no cart yet (those are Tasks 2 and 3), but the SKU carries `base_price`, `cover_price`, `moq` and the unit/conversion fields because they are columns of the same table.

## Read before coding

| What                         | Where                                                        |
| ---------------------------- | ------------------------------------------------------------ |
| Rules                        | `02-business-rules.md` §B (BR-P01..P13), §C (BR-U01..U05), BR-MOQ01, BR-PR01, BR-PR10 (fields only) |
| Tables                       | `07-domain-model.md` §3 Catalog, §4 `skus`, §13 indexes       |
| Endpoints                    | `08-api-contract.md` §1 (store products, categories, filters, price-list), §6 (dashboard catalog) |
| School-books template        | `01-product-scope.md` §2.1, BR-P09 table                      |
| Reference implementation     | `backend/app/Modules/Tenancy` (shape of every class), `tests/Http/Dashboard/*`, `tests/Unit/Actions/*` |

## Deliverables (in build order)

### 1. Schema (one migration per table group)

`units`, `categories`, `product_field_definitions`, `product_field_options`, `products`, `product_images`, `product_relations`, `skus` exactly as `07-domain-model.md` §3–§4 (sku_inventory, price tiers belong to later tasks; `skus` still gets `base_price`, `base_price_percent`, `cover_price`, `moq`, unit columns). Indexes from §13: `(tenant_id, status, category_id)`, GIN on `custom_fields`, trgm on `products.name`, `(tenant_id, path varchar_pattern_ops)`, unique `(tenant_id, code)`.

### 2. Module `app/Modules/Catalog`

- **Enums**: `ProductStatus` (active, coming_soon, inactive), `FieldType` (text, number, select, boolean), `RelationType` (revision_of, companion), `CategoryStatus`/`UnitStatus`/`FieldStatus` (active, archived) — or one shared `ArchiveStatus` enum if identical; decide and note it in the module README docblock.
- **Models** (final, `BelongsToTenant`, `@property` docblocks, casts, factories with states): `Unit`, `Category` (+ `Scopes/Active`, materialized `path`), `ProductFieldDefinition`, `ProductFieldOption`, `Product` (`#[ObservedBy(ProductObserver)]` for slug + path-independent invariants only), `ProductImage`, `ProductRelation`, `Sku`.
- **Data**: `CategoryData`, `UnitData`, `FieldDefinitionData`, `FieldOptionData`, `ProductData` (includes nested `SkuData` and `custom_fields` map), `ProductRelationData`, `BulkStatusData` (filter + status).
- **Actions**: `CreateCategory`, `UpdateCategory` (move with cycle check → `CategoryCycle` exception), `ArchiveCategory`, `RestoreCategory`, `ReorderCategories`, `CreateUnit`, `UpdateUnit`, `ArchiveUnit` (409 `in_use` when a SKU uses it), `CreateFieldDefinition`, `UpdateFieldDefinition` (type change with values → 409), `ArchiveFieldDefinition`, `CreateFieldOption`, `UpdateFieldOption`, `ArchiveFieldOption`, `CreateProduct` (product + single SKU + `opening_stock` is **ignored until Task 3**; accept and reject with 422 `not_supported_yet`? No: do not accept the field at all in this task), `UpdateProduct`, `SetProductStatus`, `DuplicateProduct` (BR-P11), `AddProductImage`, `RemoveProductImage`, `ReorderProductImages`, `AddProductRelation`, `RemoveProductRelation`, `BulkSetProductStatus` (BR-P12, one audit batch), `ApplyVerticalTemplate` (BR-P09; registered on `TenantCreatedHooks`).
- **Services**: `CustomFieldValidator` (validates a `custom_fields` map against definitions; returns normalised map with option ids), `VerticalTemplates` (reads `database/verticals/*.php`), `CategoryPath` (builds/rebuilds materialized paths), `SlugGenerator`.
- **Queries**: `CategoryTreeQuery`, `StoreProductListQuery` (filters: category incl. descendants, `f[key]`, `q` over name + sku code + select option labels, sort), `FacetQuery` (counts for the current selection, cached per tenant, invalidated by `ProductObserver`), `DashboardProductListQuery`, `PriceListQuery` (max 2000 rows), `ProductDetailQuery` (with gallery + relations).
- **Policies**: none in this task (dashboard CRUD, rules/02 §2).
- **Exceptions**: `CategoryCycle`, `UnitInUse`, `FieldTypeLocked`, `InvalidCustomFields` (422 with `details` per key), `ProductNotEditable`.
- **Http**: Requests with typed accessors for every endpoint; Resources: `CategoryResource` (recursive), `UnitResource`, `FieldDefinitionResource`, `StoreProductResource` (hides prices when `require_login_for_prices` and no customer — customer guard does not exist yet, so "no customer" is always true: implement the `when()` against `$request->user('customer')` guarded by `array_key_exists` like `AuditLogger`), `DashboardProductResource`, `ProductImageResource`, `ProductRelationResource`, `PriceListRowResource`; Controllers one per resource/verb as in rules/02 §4; route files `routes/api/store/catalog.php`, `routes/api/dashboard/catalog.php`.
- **Vertical templates**: `database/verticals/school-books.php`, `stationery.php`, `hardware.php` (BR-P09 table). `tenant:create --vertical=school-books` must seed 7 definitions with options; add the hook registration in a `CatalogServiceProvider` (register in `bootstrap/providers.php`).

### 3. Endpoints (all from `08-api-contract.md`)

Store: `GET categories`, `GET filters`, `GET products`, `GET products/{slug}`, `GET price-list`.
Dashboard: categories (6), units (4), fields (7), products (12 incl. duplicate, images, relations, bulk status). `PUT products/{id}/pricing` and `/pricing/bulk` are **Task 2**; inventory endpoints are **Task 3**.

### 4. Tests (rules/01-testing.md §4, write the `->todo()` list first and show it)

Minimum per endpoint: happy path with exact resource keys, validation dataset, 401 guest (add every route to `tests/Http/SecuritySweepTest.php`), BR-T04 cross-tenant 404 + untouched, audit row for every mutation. Plus:

- Unit/Actions: one file per action; `DuplicateProduct` copies fields/units/price/moq and gets a new code and `inactive` status; `BulkSetProductStatus` writes one batch id; `ApplyVerticalTemplate` is idempotent.
- Unit/Services: `CustomFieldValidator` datasets (required, wrong type, unknown key, archived option, select stores option id); `CategoryPath` move + cycle; `SlugGenerator` Arabic + collision.
- Unit/Queries: facet counts respect other selected facets; search finds "علوم تانية اعدادي الأضواء"; descendants included; `coming_soon` visible but `in_stock=false`; inactive hidden on store, visible on dashboard.
- Unit/Enums, Unit/Resources, Unit/Data per the rules.
- Arch: add Catalog namespaces to `tests/Arch/ModulesBoundariesTest.php` (Catalog may use Tenancy only via `TenantContext`, `BelongsToTenant`, `TenantCreatedHooks`, Audit only via `AuditLogger`).
- Console: `tenant:create --vertical=school-books` seeds the template.

### 5. Definition of done

- `composer test` green (lint, PHPStan max, type coverage 100%, Pest incl. arch).
- Curl smoke test on `php artisan serve`: create a category, a unit, a product with custom fields, list it on the store with a facet filter and `q`, duplicate it, bulk-inactivate by `academic_year`.
- Docs: `08-api-contract.md` updated if any shape changed; new rule ids referenced in tests.
- Commit message lists the test delta ("+N tests") and the BR ids covered.
- Update this file: status → **done**, with the commit hash.

## Out of scope here (next tasks)

- Task 2 — Pricing: tiers, `PriceResolver`, bulk pricing by filter, cover-price recompute.
- Task 3 — Inventory: `sku_inventory`, movements, adjustments, BR-INV08, opening stock on product create.
- Task 4 — Customers + Messaging (OTP WhatsApp/SMS, invitations, cart).
- Task 5 — Orders/Checkout. Task 6 — Collections + Reports. Task 7 — Storefront UI. Task 8 — Dashboard UI.

## Open questions for the product owner (answer in `06-open-decisions.md` before or during the task)

- D25: Should a product be allowed with zero categories (uncategorised) or is `category_id` required? Default: required.
- D26: Maximum gallery images per product. Default: 8, 2 MB each.
