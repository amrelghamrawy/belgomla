# Task 9 — Storefront UI

Status: **done**, with one open item: Lighthouse mobile performance measures 78–81, not ≥ 90 (see the delivery notes at the end).

## Goal

The customer-facing store on mobile first: home, category browsing, faceted product list with search, product page with related editions, price list page, auth (register/OTP/login/reset/invitation), account + addresses, cart, checkout, orders.

## Read before coding

| What      | Where                                                                        |
| --------- | ---------------------------------------------------------------------------- |
| Product   | `01-product-scope.md` §2.1 school books, §6–§7; `03-v1-v2-roadmap.md` §2 Storefront |
| Rules     | BR-P08 facets & search, BR-P13 price list, BR-CU04..CU08, BR-PR05/06 cart & checkout messages, BR-O02 customer cancel |
| Contract  | `08-api-contract.md` §1–§3                                                   |
| Decisions | D1, D2, D6 (no customer in-app notifications), D18 facet order, D21 coming soon |

## Pages (App Router, `app/(store)`)

1. `/` home: branding, category grid, featured = newest active products, search box.
2. `/c/[slug]` category: product grid + facet drawer (Grade → Subject → Publisher → rest; counts; AND; URL-synced `?f[grade]=`), sort, infinite scroll (TanStack `useInfiniteQuery`), "coming soon" badge, hidden prices state with login CTA.
3. `/p/[slug]` product: gallery, cover vs wholesale price + discount %, tiers table, MOQ, unit + conversion, custom fields, related editions ("نسخ أخرى من هذا الكتاب"), quantity stepper respecting MOQ/stock, add to cart.
4. `/search?q=` results reuse the list component.
5. `/price-list?...` print-friendly table (BR-P13) with `@media print` styles and a share button copying the URL.
6. `/account/register`, `/account/verify` (OTP input, resend countdown 60 s, "send by SMS" link), `/account/login`, `/account/forgot`, `/account/reset`, `/account/invite/[token]`.
7. `/account` profile; `/account/addresses` CRUD with default.
8. `/cart`: lines with live prices, removed-items notice (BR-P04), MOV progress bar ("باقي 300 ج.م للحد الأدنى"), empty state.
9. `/checkout`: address picker, note, submit with idempotency key; error rendering per `items[]` reason with links back to the product; success page with order number.
10. `/account/orders`, `/account/orders/[number]`: status timeline (history), items, collection status, cancel button only when pending (BR-O02) with reason modal.
11. Global: 503 store unavailable page, 404 page, offline/retry toasts, Arabic copy in `lib/copy/ar.ts`.

## Non-functional

- Lighthouse mobile ≥ 90 performance on category page with 24 products; images via `next/image` with backend-provided URLs; RTL verified; keyboard/focus for the facet drawer; no layout shift on price load.
- Every API error code in `08-api-contract.md` §0 has a mapped Arabic message or falls back to the envelope message.

## Tests

- Vitest + Testing Library: facet URL sync, quantity stepper MOQ/stock rules, MOV bar, checkout error mapping, OTP resend countdown (fake timers).
- Playwright: register → OTP (read from the backend log driver through a tiny test-only endpoint `GET /api/_test/last-otp` registered only in the `local` and `testing` environments, D62) → browse → add to cart → checkout → see order; price list print view snapshot.

## Definition of done

Flows above pass on a phone-sized viewport against the real backend; `npm run typecheck && lint && test` green; e2e run recorded in this file; mark done.

## Open questions

- D41: Home page content for merchants without categories yet. Resolved with its default (06-open-decisions): an empty state with the WhatsApp (or phone) contact button.

## Delivery notes (commits d7f1718 backend, 009b240 frontend)

**Results:**
- Backend `composer test` is green: 2694 tests (2651 passed + 43 arch tests flagged deprecated by PHP 8.5), +26 over Task 8.
- Frontend `typecheck`, `lint` and `build` are green.
- 303 Vitest tests pass (+194).
- 16 Playwright tests pass (8 per project: mobile 390×844 and desktop 1280×800), run on 2026-10-10 against `php artisan serve` + `next dev` with the database freshly seeded:
  - register → OTP (`/api/_test/last-otp`) → category → grade facet (URL) → product (related editions) → quantity 6 → cart (MOV bar) → new address → checkout → success → order page → cancel with reason → orders list;
  - a guest sees prices and is sent to log in from add-to-cart;
  - the price list in print media (snapshot, header/footer/buttons hidden) and the share button;
  - Task 8's four smoke flows.
- Curl smoke through `alnoor.gomal.test:8000`: facets with counts, `f[grade]` filtering (16 products), price list meta, register → `/api/_test/last-otp` code.
- Every screen was checked at 360px (screenshots) and on the desktop project. Lighthouse accessibility is 98–100.
- Rule ids covered by tests: BR-P04, BR-P08, BR-P10, BR-P13, BR-PR04, BR-PR06, BR-PR10, BR-MOQ01, BR-MOV01/02, BR-CU03–CU08, BR-O02, D1, D2, D6, D18, D21, D37, D41, D50–D53, D55, D57, D61–D63.

**Open item: Lighthouse mobile performance on `/c/[slug]` (24 products) is 78–81, below the 90 target.**
- Measured with `next start` + `php artisan serve`, Lighthouse 12 (simulated throttling): FCP 0.9 s, LCP 4.3–4.8 s, TBT 160–290 ms, CLS 0. Observed (unthrottled) LCP is 0.5–0.8 s. With devtools throttling: about 70.
- Already done: the first page and the category tree are rendered on the server (`lib/server/store-data.ts` → `HydrationBoundary`, no refetch on hydration). Zod and the OTP input were moved off the page's bundle, and the header's account/cart links do not prefetch. Together these took the score from 69 to about 80 and TBT from 440 ms to about 200 ms.
- What remains: the listing streams in a Suspense boundary (request-time data under `cacheComponents`). It is revealed after the framework scripts load, so Lighthouse's model charges all of React's hydration (react-dom alone is 74 KB gzip) to LCP.
- Next step, if the target stands: render the grid as Server Components with only the facet drawer, sort and "load more" as client islands, and re-measure on the production stack (Nginx + PHP-FPM, where the API answers much faster than `artisan serve`).

What differs from the plan above, and why:
- **Backend additions:** the plan said "against the real backend". To make that possible:
  - all 25 missing storefront operations were added to the OpenAPI spec, each response checked against its schema (`OpenApiStorefrontTest`);
  - cart lines carry `product_slug` (checkout errors link back to the product) and `conversion_factor` (`available` is in base units);
  - a demo catalog seeder (34 books, MOV 500);
  - public image URLs are relative (D61);
  - invitation links point to `/account/invite/{token}`.
- **`/api/_test/last-otp`:** registered in the `local` and `testing` environments, not `testing` only (D62). Playwright reuses the dev backend, which runs as `local`.
- **Facets:** a facet takes one value (`f[key]=id`, the backend's rule), so each group is a radio group with "الكل", and groups combine with AND.
- **Profile:** `/account` is read-only (D63): the API has no profile update.
- **Password reset:** the verified code travels from `/account/verify` to `/account/reset` in memory only. A reload starts again at `/account/forgot`.
- **http:** dev and LAN stores are not a secure context. The checkout key uses `crypto.getRandomValues` and the share button falls back to a selection copy (rules/03 §4).
- **Open decisions:** D41 (home without categories → WhatsApp/phone contact) and D61–D63 were added with their defaults.
