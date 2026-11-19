# Product

<!-- impeccable:product-schema 1 -->

## Platform

web

## Users

- **Storefront:** retail shop owners (bookshops, stationery, small goods) ordering wholesale from one merchant, mostly on phones.
- **Dashboard:** the merchant's team, in two situations of equal weight:
  - the **owner** (and manager) on a phone, away from the shop, checking orders, money owed and stock between other tasks;
  - **staff** (sales, warehouse, accountant; D82 roles) on a desktop or tablet in the shop or warehouse for most of the day, processing orders, stock and collections.

## Product Purpose

BelGomla (بالجملة) is a multi-tenant B2B wholesale ordering SaaS: each merchant gets its own storefront on a subdomain and its own dashboard. V1 is a focused wholesale ordering platform, not an ERP (`src-md-files/01-product-scope.md`).

The dashboard's daily jobs, in the owner's priority (2026-11-19):

1. **Follow new orders:** confirm, prepare, deliver, and catch the stale ones (D10, D81).
2. **Stock and products:** receive goods, see low stock, edit products and prices.
3. **Collections and debts:** who owes what, record payments (BR-C04, D59, D103).

Reports and profit (D100) matter, but come after those three. Success = a team member opens the dashboard and knows within seconds what needs action today.

## Positioning

A wholesale merchant's own store and back office, not a marketplace: customers see only that merchant. Built around Egyptian wholesale realities: quantity tiers and MOQ, school-book catalogs (grade, subject, publisher, term), merchant-run delivery, and offline cash collection tracked separately from delivery.

## Operating Context

- Arabic only, RTL-first, mobile-first; currency EGP, timezone Africa/Cairo.
- Merchant delivers and collects money itself; an order can be delivered and still unpaid.
- Orders arrive from the storefront or are placed by staff on a customer's behalf (BR-O11).
- Printed A4 order documents exist (D101); WhatsApp is used for OTP, invitations and reminders (D70, D102).

## Capabilities and Constraints

- Stack is fixed: Next.js 16, React 19, Tailwind 4, shadcn/ui RTL (`rules/03-frontend.md`).
- Roles are a fixed set with a permission map (D82); navigation and pages hide what a role cannot use.
- No hard-coded Arabic copy, colours or tenant name in components; copy lives in `lib/copy/ar.ts`.
- Dark mode tokens exist but there is no theme switch: undecided.

## Brand Commitments

- **Storefront:** the D67 identity, approved by the product owner. One design language for every store, coloured by each tenant. Do not change it as part of dashboard work.
- **Dashboard:** carries the **BelGomla platform identity**, the same for every merchant (decided 2026-11-19, D104). The tenant's name and logo appear in it, but the tenant's colours do not drive the dashboard theme.
- Fonts already in use: IBM Plex Sans Arabic for body text (D40), Cairo for display in the storefront (D67).
- No BelGomla logo or platform brand assets exist yet in the repo; do not invent a logo file. Open.

## Evidence on Hand

- Demo tenant "مكتبة النور" (alnoor) with seeded catalog, orders, customers and reports (demo seeders) for realistic screens.
- No testimonials, metrics or customer quotes exist; none may be fabricated.

## Product Principles

1. **Action first:** every screen leads with what needs doing now (pending, stale, owed, low), then the history.
2. **Works in the hand and at the desk:** every dashboard task is completable on a phone; dense work is comfortable on a desktop.
3. **Money and quantities are exact and scannable:** numbers are never ambiguous, never misaligned.
4. **One platform, many merchants:** the dashboard feels like one coherent BelGomla product, whichever store is using it.

## Accessibility & Inclusion

WCAG 2.2 AA. Arabic RTL throughout; touch targets sized for phones (rules/03 uses 44px on mobile).
