# BelGomla (بالجملة) — Session Instructions

This project spans many sessions. Every session must start by reading, in order:

1. `rules/00-session-start.md` — how to work in this repo.
2. `rules/01-testing.md` — the testing standard. Non-negotiable.
3. `rules/02-architecture.md` — which class to write in which situation, naming, module layout. Non-negotiable.
4. Any other `rules/*.md` that exist.
4. `src-md-files/06-open-decisions.md` and `src-md-files/07-domain-model.md` for current product state; the rest of `src-md-files/` as needed.

Rules in `rules/` override defaults and personal habits. If a rule cannot be followed, say so explicitly in the reply instead of silently skipping it.

Quick facts:

- Backend: `backend/` (Laravel 12, PHP 8.5, PostgreSQL 16, Redis). No Docker, ever.
- Modules live in `backend/app/Modules/<Module>/`; cross-module calls go through services.
- Tests run with `composer test` inside `backend/` and must be green before a feature is called done.
- Frontend: `frontend/` (Next.js 16, React 19, Tailwind 4, shadcn/ui RTL), conventions in `rules/03-frontend.md`. `npm run typecheck && npm run lint && npm test` inside `frontend/` must be green; `npm run test:e2e` for user flows.
- API types: `backend/openapi/openapi.yaml` → `php artisan api:openapi` → `npm run api:types` in `frontend/`.
