# V3 Task 04 — Platform admin accounts and admin host

Status: **planned**. Requires V3 Task 00 only (free to run in parallel with 01–03). Read `CLAUDE.md`, `rules/*.md`, then this file.

## Why

D111: the platform operator needs a login that is not a tenant user, on a host that no tenant can reach.

## Today the code does

- Guards (`backend/config/auth.php:40-54`): `web` and `tenant_user` (provider `tenant_users`), `customer`. No platform guard.
- Routes (`backend/routes/api.php`): `/api/store/...` and `/api/dashboard/...` on tenant hosts `{subdomain}.{base_domain}` (resolved by `ResolveTenant`), `/api/webhooks/...` on the platform host `hooks.{base_domain}`.
- `audit_logs.tenant_id` is **not null** with cascade (`..._audit_logs` migration :15), so platform actions cannot be logged there.
- Tenant management is CLI only (`Tenancy\Console\Tenant*Command`).

## Schema

- `platform_admins`: `id`, `name`, `email` unique (lower-cased), `password`, `status` (`active` | `disabled`), `last_login_at`, `remember_token`, timestamps.
- `platform_audit_logs`: `id`, `platform_admin_id` FK nullable (null = CLI), `action` varchar(60), `tenant_id` FK nullable (set null on delete), `entity_type`, `entity_id`, `changes` jsonb, `ip`, `created_at`. Index (tenant_id, created_at), (action, created_at).

## Code

- Guard `platform_admin` (session, provider `platform_admins`). Session cookie scoped to `admin.{base_domain}` only (not the parent domain), so a tenant session never carries over.
- `routes/api/admin/<module>.php` → `/api/admin/...` under `Route::domain('admin.'.base_domain)`; `ResolveTenant` never runs there; a request on a tenant host for `/api/admin/*` is 404, and `/api/dashboard/*` on the admin host is 404.
- `Platform\Actions\AuthenticatePlatformAdmin` (same shape as `AuthenticateTenantUser`): throttle 5/min per email+IP, generic error, `last_login_at`, audit `admin.login`.
- `POST /api/admin/session`, `DELETE /api/admin/session`, `GET /api/admin/me`.
- `Platform\Services\PlatformAuditLogger` (used by every admin Action from here on, and by the `tenant:*` commands with a null admin).
- Console: `platform:admin-create {email} --name=` (asks for the password, never as an argument in production), `platform:admin-disable {email}`. The only way to create an admin (D111 b).
- CORS / Sanctum stateful domains: add `admin.{base_domain}`.

## Tests

- Login success, wrong password, disabled admin, throttle.
- Isolation: a tenant user's credentials fail on the admin host; an admin session cookie is not accepted on a tenant host; `/api/admin/me` from a tenant host 404.
- Arch: `/api/admin` routes all carry `auth:platform_admin` except the session store.
- `platform:admin-create` writes a hashed password and a `platform_audit_logs` row.

## Definition of done

rules/00 DoD. `scripts/smoke.sh` needs an `admin` login mode (`admin.gomal.test`); add it in this task.
