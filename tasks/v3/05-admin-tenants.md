# V3 Task 05 — Admin API: tenants

Status: **planned**. Requires V3 Tasks 01 and 04. Read `CLAUDE.md`, `rules/*.md`, then this file.

## Why

The operator creates, finds, suspends and activates stores from the admin dashboard instead of SSH + artisan (BR-T07 as rewritten in 00).

## Today the code does

- `Tenancy\Actions\CreateTenant` (+ `NewTenantData`, `TenantCreatedHooks` → catalog provisioning), `SetTenantStatus`, `Queries\TenantOverviewQuery` (used by `tenant:list`), `TenantBySubdomainQuery`.
- Tenant-owned models are scoped by `TenantScope`; platform code may bypass it only with a comment (rules/00 §Never).

## Endpoints (`/api/admin/tenants`, guard `platform_admin`)

| Method | Path | What |
| ------ | ---- | ---- |
| GET | `/tenants` | Paginated; `q` (name / subdomain / owner phone or email), `status`, `vertical`; columns: name, subdomain, vertical, status, created_at, products count, orders (last 30 days), last dashboard login. D111 c: counts only. |
| GET | `/tenants/{id}` | The same plus owner (name, email/phone), store URL, sections count, custom fields count, WhatsApp link status (D70: connected / not). |
| POST | `/tenants` | `CreateTenant` with name, subdomain, vertical key, owner name/email/phone/password. Same validation as the CLI (one `NewTenantData`). |
| POST | `/tenants/{id}/suspend` · `/activate` | `SetTenantStatus`, with a required reason on suspend. |
| PUT | `/tenants/{id}/vertical` | D108 d: allowed only while the tenant has no products; re-provisions sections and templates of the new vertical; the old (empty) sections are archived. |

Each write is audited in `platform_audit_logs` with `tenant_id`.

## Code

- `Tenancy\Queries\AdminTenantListQuery` / `AdminTenantDetailQuery` (cross-tenant reads with `withoutTenantScope()` and a comment citing D111). Counts in one query per page (no N+1), indexed columns only.
- `Tenancy\Actions\ChangeTenantVertical`.
- Controllers in `Platform\Http\Controllers\Admin\` call Tenancy Actions/Queries only (rules/02 cross-module rule).

## Tests

- List filters and search; counts on a fixture with two tenants (expected values written first).
- Create via API equals create via CLI (same rows).
- Suspend → the storefront and dashboard of that tenant answer `TenantSuspended`; activate restores.
- Change vertical refused with one product; allowed with none.
- Non-admin (tenant user session, guest) → 401/404.

## Definition of done

rules/00 DoD; smoke: admin login → create tenant → suspend → activate.
