# V3 Task 09 — Release

Status: **planned**. Requires every other V3 task. Read `CLAUDE.md`, `rules/*.md`, `deploy/README.md`, then this file.

## Steps

1. DNS: `admin.{base_domain}` A record; TLS certificate covers it (check whether the current certificate is a wildcard; if it is, nothing to do).
2. `deploy/`: nginx server block for the admin host (same upstreams as the dashboard), optional IP allow-list (`ADMIN_ALLOWED_IPS`, empty = open, D111 d), rate limit on `/api/admin/session`.
3. `deploy.sh` post-deploy assertions: `/api/admin/me` on a tenant host is 404; on the admin host it is 401.
4. On the VM: run the migrations of 01/02 on a copy of production first (`pg_dump` → `belgomla_rehearsal`), compare facets / price list / sales analytics of one real store before and after (task 02 checklist), then the real deploy.
5. `platform:admin-create` for the operator(s) recorded with the platform owner (D45).
6. Docs: `rules/00-session-start.md` §Production gets the admin host and the admin commands; `tasks/v3/README.md` statuses and commits.

## Definition of done

Live: admin login works, the store list shows every tenant on «مكتبات», a pushed test field appears in one store and is archived again by that store.
