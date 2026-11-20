# V3 Task 06 — Admin API: verticals, sections, fields, push to tenants

Status: **planned**. Requires V3 Tasks 02 and 04. Read `CLAUDE.md`, `rules/*.md`, then this file.

## Why

D108/D110: the operator manages the defaults of every vertical (today only «مكتبات») and can add a new vertical (ملابس) without a deploy, then push new defaults to existing stores without touching what each store changed.

## Endpoints (`/api/admin`, guard `platform_admin`)

- Verticals: `GET /verticals`, `POST /verticals`, `PUT /verticals/{id}` (name, sort), `POST /verticals/{id}/archive|restore`. Archived = not offered for new tenants; existing tenants keep it. `key` immutable.
- Sections: `GET /verticals/{id}/sections`, `POST`, `PUT /sections/{id}` (name, reference price label, icon, sort), `POST /sections/{id}/archive|restore`, `POST /verticals/{id}/sections/reorder`.
- Field templates: `GET /verticals/{id}/fields`, `POST`, `PUT /fields/{id}` (label, section, required, filterable, orders), `POST /fields/{id}/archive|restore`, options `POST /fields/{id}/options`, `PUT /field-options/{id}`, reorder. Same validation as the tenant field endpoints (`StoreFieldRequest`), `type` immutable after create.
- Push: `POST /verticals/{id}/push?dry_run=1` → per tenant of the vertical: sections, fields and options that would be **added**; `POST /verticals/{id}/push` → queued `PushVerticalDefaults` job per tenant.

## Push rules (D110)

- Additive only: adds a section whose `vertical_section_id` the tenant does not have, a field whose `template_id` (or key, for pre-linked tenants) it does not have, an option whose label the field does not have.
- Never renames, never re-orders, never changes type / required / section, never archives or restores anything at the tenant.
- A tenant field with the same key but no `template_id` (tenant-made) is **not** overwritten: it is reported as a conflict in the dry run and skipped.
- A section or field the tenant archived stays archived (the tenant chose not to use it).
- Idempotent; a re-run adds nothing. Audited per tenant (`platform_audit_logs`) and in the tenant's `audit_logs` (`catalog.defaults_pushed`).

## Code

- `Platform\Actions\*` for the CRUD; `Catalog\Actions\ApplyVerticalDefaults` (the same Action as provisioning in 02, run for an existing tenant inside `TenantContext`); `Platform\Jobs\PushVerticalDefaults` dispatches it per tenant.

## Tests

- CRUD validation (duplicate key per vertical, archived section cannot receive new fields).
- Push on a fixture: tenant A renamed a section and archived a field, tenant B made its own `brand` field; dry run lists exactly the expected additions and B's conflict; real run adds them; second run adds nothing.
- A new vertical «ملابس» with two sections, then `POST /api/admin/tenants` with it: the store gets those sections and fields.

## Definition of done

rules/00 DoD; smoke: add a field to «كتب خارجية», dry run, push, see it in a demo store's field list.
