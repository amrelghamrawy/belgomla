# V3 Task 08 — Super admin frontend

Status: **planned**. Requires V3 Tasks 05 and 06. Read `CLAUDE.md`, `rules/*.md` (incl. `03-frontend.md`), then this file.

## Why

The operator's UI for tasks 05 and 06.

## Not checked yet (verify first)

How the Next app tells a tenant host from another host today (`frontend/app/(dashboard)`, `(store)`, `next.config.ts`; there is no `middleware.ts` / `proxy.ts` at the root). Pick the mechanism that keeps the admin pages unreachable on tenant hosts (server-side host check that 404s), record it in `rules/03-frontend.md`.

## Pages (route group `(admin)`, host `admin.{base_domain}`, D104 BelGomla identity, RTL)

- `/login`.
- `/tenants`: table (search, status and vertical filters, counts from task 05), row actions as icon pills (same style as the dashboard tables, commit 84b2bcf), «متجر جديد» dialog.
- `/tenants/[id]`: details, suspend (reason) / activate, change vertical (disabled with the reason when the store has products), link to the store.
- `/verticals`: list, add, archive.
- `/verticals/[id]`: tabs «الأقسام» and «الحقول»: reorder, edit dialogs, options editor for select fields; «نشر للمتاجر» → dry-run summary (per store: added sections/fields/options, conflicts) → confirm.
- `/audit`: platform audit log, filter by tenant and action (read-only).

API client: a separate `lib/admin/` with its own fetch wrapper (CSRF + session on the admin host), types from `npm run api:types`.

## Tests

- Unit for the push summary formatting and the vertical-change guard.
- E2E: login → create store → suspend/activate; add a field template → dry run → push.
- E2E isolation: `/tenants` on a tenant host is 404.

## Definition of done

`npm run typecheck && npm run lint && npm test && npm run test:e2e` green; `/preflight`.
