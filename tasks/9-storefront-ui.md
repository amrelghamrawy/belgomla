# Task 9 — Storefront UI

Status: **not started**. Requires Tasks 1–8. Read `CLAUDE.md`, `rules/*.md` (incl. `03-frontend.md`), then this file.

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

- D41: Home page content for merchants without categories yet (show "قريبًا"?). Default: empty-state with WhatsApp contact button.
