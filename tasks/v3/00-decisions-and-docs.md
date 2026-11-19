# V3 Task 00 — Decisions and documents

Status: **planned**. Docs only, one commit. Read `CLAUDE.md`, `rules/*.md`, `tasks/v3/README.md`.

## Why

The platform admin was a "Later" item and V1/V2 documents say in several places that there is none (CLI only). The vertical and the sections are code enums today. Every later V3 task cites the decisions and rules written here.

## Steps

1. `06-open-decisions.md`: D108–D111 are already in the table as **proposed** (added with this board). Confirm them with the product owner; record any change in the row and in the task files that cite it.
2. `02-business-rules.md`:
   - **BR-T07** (tenant lifecycle): tenants are created, suspended and activated by the platform admin dashboard **or** the CLI (both call the same Actions).
   - **BR-T09 — Platform admin (new, §A):** who the platform admins are, the admin host, what they see of a tenant (D111).
   - **BR-P06** (categories): a root category belongs to a **section** of the tenant (replaces "carries a `kind`", D68 → D109).
   - **BR-P08** (custom fields): a field definition belongs to one section of the tenant or to none (every section) (replaces D69's `kind`).
   - **BR-P09** (vertical templates): rewritten: the templates are platform data per vertical (D108, D110), copied at tenant creation, pushed additively later.
   - **BR-P16 — Store verticals and sections (new, §B):** D108 + D109 + D110 as rules.
   - **BR-PR10**: the reference price label comes from the product's section (`reference_price_label`), no longer from `kind = books`.
3. `07-domain-model.md`: new tables `verticals`, `vertical_sections`, `vertical_field_templates`, `vertical_field_template_options`, `sections`, `platform_admins`, `platform_audit_logs`; `tenants.vertical_id`; `categories.section_id` and `product_field_definitions.section_id` replacing `kind`; ER diagram lines.
4. `01-product-scope.md` §84, `03-v1-v2-roadmap.md` (§Later Platform Capabilities: "Platform Admin UI" and "Platform-wide tenant management" → "V3, tasks/v3"), `04-technical-constraints.md` §18 (title and text: CLI **and** admin dashboard), `05-module-scope.md` §160.
5. `08-api-contract.md`: a new section for `/api/admin/...` (filled by tasks 04–06) and a note in §16 Build Order pointing to `tasks/v3/`.

## Definition of done

Documents consistent with each other (grep `kind`, `Platform Admin`, `no Platform Admin UI`, `CLI only`), commit `V3 Task 00: platform admin and store verticals decided (D108–D111)`.
