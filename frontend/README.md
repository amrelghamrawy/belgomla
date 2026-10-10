# BelGomla frontend (Next.js)

Storefront and merchant dashboard for every tenant, served on the tenant's own host. Arabic, RTL, mobile-first. The conventions are in [`rules/03-frontend.md`](../rules/03-frontend.md); read them before changing anything here.

## Run locally (Windows, no Docker)

1. Backend running: `cd ../backend && php artisan serve` (migrated and seeded; seeded store `alnoor`, owner `owner@alnoor.test` / `password`).
2. `npm install`, then `cp .env.example .env.local` (the defaults match `php artisan serve`).
3. `npm run dev`, then open **http://alnoor.gomal.test:3000**. The browser needs the hosts entry `127.0.0.1  alnoor.gomal.test` (admin PowerShell: `Add-Content C:\Windows\System32\drivers\etc\hosts "`r`n127.0.0.1  alnoor.gomal.test"`). Without it, use curl with `--resolve alnoor.gomal.test:3000:127.0.0.1`.

`/api/*` and `/sanctum/*` go to Laravel through the route handlers in `app/api` and `app/sanctum` (`lib/server/backend-proxy.ts`). The handlers keep the browser's `Host`, which is how Laravel knows the tenant. Opening the app on `localhost` therefore shows «المتجر غير موجود».

## Scripts

| Script                    | What                                                                                                                                                                                       |
| ------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `npm run dev`             | dev server on :3000                                                                                                                                                                        |
| `npm run build` / `start` | production build / server                                                                                                                                                                  |
| `npm run typecheck`       | `next typegen` + `tsc --noEmit`                                                                                                                                                            |
| `npm run lint`            | ESLint, including the RTL rule against physical direction classes                                                                                                                          |
| `npm test`                | Vitest unit and component tests                                                                                                                                                            |
| `npm run test:e2e`        | Playwright on mobile and desktop. Starts `php artisan serve` and `next dev` if they are not running; Chromium maps `*.gomal.test` itself. Run `npx playwright install chromium` once first |
| `npm run api:types`       | regenerate `lib/api/schema.d.ts` from `../backend/openapi.json`                                                                                                                            |
| `npm run format`          | Prettier                                                                                                                                                                                   |

A change is done when `npm run typecheck && npm run lint && npm test` is green. Also run `npm run build` when you touch routing or config, and `npm run test:e2e` for user flows.

## Adding an endpoint

1. Describe it in `backend/openapi/openapi.yaml`.
2. Run `php artisan api:openapi` in `backend/`.
3. Run `npm run api:types` here.
4. Add the hook (rules/03 §2).
