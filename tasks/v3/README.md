# V3 Task Board — Platform admin, store verticals and sections

V3 = the first "Platform Capabilities" item of `src-md-files/03-v1-v2-roadmap.md` §Later, pulled forward by the product owner (2026-11-19): a **super admin dashboard** that manages every tenant, and the **store vertical** (نشاط المتجر) each tenant belongs to, with its default **sections** and their **custom fields**. Each tenant gets a copy of its vertical's defaults and may add to them, hide what it does not use and edit the rest. Later verticals (ملابس، أحذية، إكسسوارات موبايل) are added from the admin dashboard as data, not code.

One task = one session (or a few). Execute in order unless the dependency list says a task is free. Before starting any task read `CLAUDE.md` and `rules/*.md`, then the task file.

| #  | Task                                                                  | Module(s)                              | Status  | Commit |
| -- | --------------------------------------------------------------------- | -------------------------------------- | ------- | ------ |
| 00 | [Decisions and documents](00-decisions-and-docs.md)                   | docs                                   | done    | see log |
| 01 | [Platform verticals, sections and field templates](01-platform-verticals.md) | Platform, Tenancy, Catalog      | planned |        |
| 02 | [Tenant sections replace `CategoryKind` (backend)](02-tenant-sections-backend.md) | Catalog, Pricing, Reports, Tenancy | planned |        |
| 03 | [Tenant sections in the frontend](03-tenant-sections-frontend.md)     | frontend, Catalog resources            | planned |        |
| 04 | [Platform admin accounts and admin host](04-platform-admin-auth.md)   | Platform, Tenancy                      | planned |        |
| 05 | [Admin API: tenants](05-admin-tenants.md)                             | Platform, Tenancy                      | planned |        |
| 06 | [Admin API: verticals, sections, fields, push to tenants](06-admin-verticals.md) | Platform, Catalog           | planned |        |
| 07 | [Merchant dashboard: sections management](07-dashboard-sections.md)   | Catalog, frontend                      | planned |        |
| 08 | [Super admin frontend](08-admin-frontend.md)                          | frontend                               | planned |        |
| 09 | [Release](09-release.md)                                              | deploy, docs                           | planned |        |

## Why this order

- **00** writes D108–D111 and the new business rules first, so every later task cites them.
- **01 → 02 → 03** turn today's code constants (`Tenancy\Enums\Vertical`, `Catalog\Enums\CategoryKind`, `database/verticals/*.php`) into data. 02 is the riskiest task of the board: it touches 18 backend files and ~30 test files and must not change behaviour. It runs before any admin UI exists, so a regression shows up on its own.
- **04 → 05** are independent of 01–03 and can run in parallel with them: admin login and tenant management only need the `tenants` table.
- **06** needs both chains: it edits the platform templates (01) and pushes them into tenant sections (02).
- **07** and **08** are the two UIs; they can run in parallel once their APIs exist.
- **09** opens the admin host on the VM.

## Dependencies

```
00 ─► everything
01 ─► 02 ─► 03 ─► 07
01 ─► 05
04 ─► 05 ─► 08
02, 04 ─► 06 ─► 07, 08
all ─► 09
```

Free to run out of order: 04 at any point after 00.

## Numbering

- Decisions **D108–D111** belong to this board. D104 is already taken by the dashboard identity, so `tasks/v2/21` (which proposes D104–D106) must be renumbered to D105–D107 before it starts; its file is not changed here.
- New business rules: **BR-T09** (platform admin), **BR-P16** (store verticals and sections). BR-P09 (vertical templates) is rewritten in 00.

## Rules for the board

Same as `tasks/v2/README.md` §Rules: a task is done only when its file records the commit hash and the suites are green; every API change goes `openapi.yaml` → `php artisan api:openapi` → `npm run api:types`; a product decision found mid-task goes to `06-open-decisions.md` with a default.
