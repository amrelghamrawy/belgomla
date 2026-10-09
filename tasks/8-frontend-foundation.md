# Task 8 — Frontend foundation (Next.js, RTL, auth, API client)

Status: **not started**. Requires Task 0 (Tenancy API) only; later UI tasks depend on this. Read `CLAUDE.md`, `rules/*.md`, then this file.

## Goal

A `frontend/` Next.js app served per tenant host, Arabic RTL mobile-first, with the Sanctum cookie auth flow for both guards, a typed API client generated from the contract, the error-envelope handling, and a frontend rules file so UI sessions stay consistent. No feature pages yet beyond login and "me".

## Read before coding

| What      | Where                                                                                  |
| --------- | -------------------------------------------------------------------------------------- |
| Tech      | `04-technical-constraints.md` §1 frontend, §5 hosts/URLs (`/api` proxied to Laravel), §6 Sanctum, §7 RTL |
| Contract  | `08-api-contract.md` §0 envelopes and error codes, §2 auth, §4 dashboard auth          |
| Product   | `01-product-scope.md` §6 language/UX, §7 branding fields                               |

## Deliverables

### 1. `rules/03-frontend.md` (write first, same spirit as rules/02)

Decide and document once: Next.js App Router + TypeScript strict; Tailwind with `dir="rtl"` and logical properties only (`ms-`, `me-`, `ps-`); component library choice (default: shadcn/ui with RTL patches); data layer: TanStack Query + a generated client (`openapi-typescript` from an OpenAPI spec exported by the backend — add `backend` task note: generate `openapi.json` via a small artisan command `api:openapi` in Task 8 as well); forms: react-hook-form + zod schemas mirroring the backend rules; state: no global store besides Query cache and an `AuthProvider`; folder layout `app/(store)/...`, `app/(dashboard)/dashboard/...`, `components/ui`, `components/store`, `components/dashboard`, `lib/api`, `lib/auth`, `lib/format` (money, dates in Africa/Cairo, Arabic numerals policy: Western digits, decide); testing: Vitest + Testing Library for units/components, Playwright for the three smoke flows (store home, login, dashboard me); lint: ESLint strict + Prettier; naming conventions; one Arabic copy file `lib/copy/ar.ts` (no i18n framework in V1).

### 2. Project

- `frontend/` with `npm create next-app` (TypeScript, App Router, Tailwind, ESLint), `package.json` scripts: `dev`, `build`, `lint`, `typecheck`, `test`, `test:e2e`.
- `next.config.ts`: rewrite `/api/:path*` and `/sanctum/:path*` to `BACKEND_URL` preserving the `Host` header (document the production Nginx equivalent in `11-release.md`).
- Tenant resolution on the client: none — everything is relative to the current host.
- `lib/api/client.ts`: fetch wrapper with `credentials: 'include'`, CSRF cookie bootstrap, `X-XSRF-TOKEN`, JSON envelope unwrapping, typed `ApiError` from the error envelope, 401 → redirect to the right login (store vs dashboard), 419 → refresh CSRF and retry once, 503 `store_unavailable` → full-page "المتجر غير متاح".
- `lib/auth`: `useTenant()` (GET `/api/store/tenant`, applies branding colors as CSS variables, favicon, title), `useDashboardSession()` and `useCustomerSession()`.
- Pages: `app/(store)/page.tsx` placeholder showing tenant name/logo; `app/(dashboard)/dashboard/login/page.tsx`; `app/(dashboard)/dashboard/page.tsx` showing "me"; `app/(store)/account/login` placeholder.
- Branding: CSS variables `--color-primary/--color-secondary` from settings; Arabic font (default: IBM Plex Sans Arabic via `next/font`); RTL verified in both Chrome and Safari layouts.
- Backend addition: `php artisan api:openapi` command (Tenancy or a new `Platform` module? → `app/Modules/Platform/Console/OpenApiCommand`) that writes `backend/openapi.json` from route + resource annotations; keep it simple (manual YAML under `backend/openapi/` is acceptable if generation is too costly — decide and document).

### 3. Tests

- Vitest: `client.ts` (envelope unwrap, error mapping, 419 retry once), `format/money` (`"150.00"` → `١٥٠٫٠٠`? no: Western digits "150.00 ج.م"), `useTenant` applies variables.
- Playwright (against `php artisan serve` + `next dev` with `--resolve`-style host mapping via `/etc/hosts` entry `alnoor.gomal.test`): store home renders tenant name; dashboard login → me; wrong password shows the Arabic message from the envelope.
- `npm run typecheck && npm run lint && npm test` green; e2e documented as a manual gate until CI exists.

### 4. Definition of done

Frontend runs on `http://alnoor.gomal.test:3000` with the hosts entry; login works end-to-end through the rewrite; `rules/03-frontend.md` committed; `tasks/README.md` updated; mark done.

## Open questions

- D38: Component library (shadcn/ui vs Mantine RTL). Default: shadcn/ui.
- D39: Digits in prices (Western `150.00` vs Arabic-Indic). Default: Western.
- D40: Fonts (IBM Plex Sans Arabic vs Cairo). Default: IBM Plex Sans Arabic.
