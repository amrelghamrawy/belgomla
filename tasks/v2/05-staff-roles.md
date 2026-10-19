# V2 Task 05 — Staff accounts, roles and permissions

Status: **done** (2026-10-19). Read `CLAUDE.md`, `rules/*.md`, then this file.

### Delivered

- Docs: D82; BR-T06 rewritten with the role table; 07 `tenant_users.role` CHECK and `notifications.permission`; 08 §0 `403 forbidden` on dashboard endpoints, `/me` permissions, users `role`/`409 last_owner`, notifications filter; rules/02 §2 amended (permission middleware, policies for what a permission cannot express, `CurrentPermissionsQuery`).
- Deviations, recorded in the docs: the middleware is `Tenancy\Http\Middleware\RequirePermission` (it reads the TenantUser model, so it lives in Tenancy, not `App\Support`), runs right after auth in the priority list so a forbidden role gets 403 before route binding can answer 404, and takes several permissions as "any one of them"; the gates are registered in `AppServiceProvider` (there is no Tenancy provider); reading the catalog, stock and orders needs `orders.view`, which every role has; customers can be listed with `customers.manage`, `customers.credit` or `collections.manage`, and the customer update splits name/addresses (`customers.manage`) from credit terms (`customers.credit`) in the request's `authorize()`; "collect the full amount" on delivery needs `collections.manage`; the warehouse rule is in `OrderPolicy::transition` / `cancelFromDashboard`, reading the role through `Tenancy\Queries\CurrentPermissionsQuery`; order notifications need `orders.manage` (so the accountant, who has `orders.view`, does not see new orders, as the test list asks); without a dashboard user (seeders, commands) every notification is visible; `/me` carries the permissions next to `user` and `tenant` (no separate MeResource); the shared `dashboard authenticated routes` dataset moved to `tests/Datasets`; the "every route has a permission" check is a Unit test (the Arch folder does not boot the app); a denied policy or `authorize()` now renders as `forbidden` instead of `http_error`.
- Tests: backend 3898 (+573: role table, middleware, permission matrix of 79 routes × 5 roles, role rules, users/last owner, `/me`, notifications filter, route rule), frontend 691 (+12), e2e "the owner adds a sales user, who sees orders but cannot open the settings" on mobile and desktop; the whole dashboard e2e passes on desktop.
- Smoke against `php artisan serve`: the owner creates a manager, sales, warehouse and accountant user; the last owner cannot demote themself (409 last_owner); each role against one read and one write per module answers 403 exactly where the table says; the smoke users are disabled afterwards. `tenant:create` still makes an owner (TenantCommandsTest).

## Goal

A store has more than one person on the dashboard: the owner, a sales clerk taking orders, a warehouse worker preparing and adjusting stock, an accountant recording collections. Each tenant user gets one **role**, each role a fixed set of **permissions**, and every dashboard endpoint and screen respects them. Later V2 tasks (07 manual orders, 08 discounts, 09 editing, 11 customer prices) add their own permission to this map.

Out of scope: custom roles defined by the merchant, per-user permission overrides (D82), branch/warehouse scoping, and two-factor login.

## Read before coding

| What      | Where |
| --------- | ----- |
| Rules     | BR-T06 (single role in V1; V2 roles), BR-N02 (notifications to owners) |
| Tables    | `07-domain-model.md` §2 `tenant_users` (`role` varchar 20, no CHECK), §9 `notifications.tenant_user_id` |
| API       | `08-api-contract.md` §dashboard auth (`/me`), users endpoints, every dashboard group |
| Arch      | rules/02 §2 (policies only for customer-facing and transitions; dashboard CRUD authorised by guard) — amended here |

## Left by V1

- `Tenancy\Enums\TenantUserRole` has only `Owner` ("V1 has a single role (BR-T06). Granular roles are V2"). `CreateTenantUser` always sets Owner. `TenantUserResource` exposes `role`. `UserController` (index/store/patch) and `UsersPage.tsx` have no role UI.
- Every `routes/api/dashboard/*.php` group uses `['auth:tenant_user', 'session.tenant:tenant_user']`. There are no gates.
- `Http/SecuritySweepTest.php` holds the `dashboard authenticated routes` dataset (each module appends its routes). Reuse it as the permission matrix's route list.
- `AuditLogger` already records `actor_type = tenant_user` and `actor_id`.
- `NotifyMerchants` writes `tenant_user_id = null` (all owners).

## Deliverables

### 1. Docs

D82 into 06. BR-T06 is rewritten with the role table below. rules/02 §2 "Policy" is amended: dashboard routes are authorised by permission middleware, and policies stay for customer-facing actions and transitions. 07 §2 gets the role CHECK. 08 gets `permissions` in `/me`, the role on users, and `403 forbidden` on every dashboard endpoint.

### 2. Roles and permissions (fixed in code)

| Permission (`Permission` enum)   | owner | manager | sales | warehouse | accountant |
| -------------------------------- | :---: | :-----: | :---: | :-------: | :--------: |
| `catalog.manage` (products, categories, units, fields, import) | ✓ | ✓ | | | |
| `pricing.manage`                 | ✓ | ✓ | | | |
| `inventory.adjust`               | ✓ | ✓ | | ✓ | |
| `orders.view`                    | ✓ | ✓ | ✓ | ✓ | ✓ |
| `orders.manage` (transition, cancel, address) | ✓ | ✓ | ✓ | ✓ (up to out_for_delivery) | |
| `orders.returns`                 | ✓ | ✓ | | ✓ | |
| `customers.manage`               | ✓ | ✓ | ✓ | | |
| `customers.credit` (limits, terms) | ✓ | ✓ | | | ✓ |
| `collections.manage`             | ✓ | ✓ | | | ✓ |
| `reports.view`                   | ✓ | ✓ | | | ✓ |
| `settings.manage` (incl. WhatsApp link) | ✓ | | | | |
| `users.manage`                   | ✓ | | | | |
| `audit.view`                     | ✓ | ✓ | | | |

Warehouse may move an order only to `preparing` / `out_for_delivery`. This is an extra check in `OrderPolicy::transition` for tenant users, not a separate permission.

### 3. Schema

`tenant_users.role` CHECK `in ('owner','manager','sales','warehouse','accountant')`. "At least one active owner per tenant" cannot be a DB constraint, so `UpdateTenantUser` enforces it: the last active owner cannot be demoted or disabled (`409 last_owner`).

### 4. Module classes

- `Tenancy\Enums\TenantUserRole`: the five cases, with `label()` in Arabic and `permissions(): list<Permission>`.
- `Tenancy\Enums\Permission`: the backed enum above.
- `App\Support\Http\Middleware\RequirePermission` (alias `permission`): `->middleware('permission:orders.manage')`. It returns `403 forbidden` with the standard envelope.
- `Gate::before` is not used. A gate per permission is registered in `TenancyServiceProvider` for Blade-free `Gate::allows('orders.manage')` in policies.
- Actions: `CreateTenantUser`/`UpdateTenantUser` take `role` (with the last-owner guard), audited `tenant_user.role_changed`.
- `NotifyMerchants`: notifications carry a `permission` (e.g. `orders.view`), and `NotificationsQuery` shows a user only the rows their role may see (the `tenant_user_id = null` broadcast is kept).
- `MeResource` adds `permissions: list<string>`.
- Every route file under `routes/api/dashboard/` gets `permission:` middleware per route (reads `orders.view`, writes the manage permission).
- Arch test: every dashboard route except `auth/*` and `me` has a `permission:` middleware.

### 5. Frontend

- `lib/dashboard/permissions.ts`: `can(me, 'orders.manage')` and a `useCan()` hook.
- `DashboardNav` hides sections without permission. Buttons for write actions are hidden or disabled. Direct navigation to a forbidden page shows a 403 state.
- `UsersPage`: role column, role picker in create/edit, the last-owner error shown.

## Test list

- `Unit/Enums/TenantUserRoleTest`: dataset role × permission = the table above (single source of truth).
- `Unit/Middleware/RequirePermissionTest`: allowed, forbidden, guest.
- `Http/Dashboard/PermissionMatrixTest`: for every route in the security-sweep dataset × every role → 403 or not 403 as the table says (one dataset, generated).
- `Unit/Policies/OrderPolicyTest`: warehouse may move to preparing / out_for_delivery only.
- `Unit/Actions/UpdateTenantUserTest`: role change audited; last owner cannot be demoted or disabled.
- `Unit/Queries/NotificationsQueryTest`: an accountant does not see `new_order` (permission filter).
- `Http/Dashboard/Tenancy/UsersTest`, `MeTest` (permissions list).
- `Arch`: every dashboard route has a permission middleware.
- Frontend: nav per role, `useCan`, users page role picker; e2e: a sales user cannot open settings.

## Slices

1. Docs + enums + schema.
2. Middleware, route annotations, matrix test.
3. Users API (roles, last owner), `/me`, notifications filter.
4. Frontend.

## Definition of done

Green suites; curl as each role against one read and one write endpoint per module; `tenant:create` still makes an owner; docs and rules/02 updated.

## Open decisions (proposed)

- **D82** Roles are a fixed platform set (owner, manager, sales, warehouse, accountant) with a fixed permission map in code. There are no custom roles and no per-user overrides. Adding a role or moving a permission is a code change. A tenant always keeps at least one active owner.
