# BelGomla — Backend (Laravel 12)

Multi-tenant B2B wholesale e-commerce API. Product documents live in `../src-md-files/`.

## Local setup (Windows, no Docker)

Requirements already on this machine: PHP 8.5 (`C:\php`), Composer, PostgreSQL 16 (service `postgresql-x64-16`), Redis (service `Redis`), Node 24.

```powershell
cd "D:\my work\backend"
composer install
php artisan migrate
php artisan db:seed          # creates tenant "alnoor" -> owner@alnoor.test / password
php artisan serve            # http://127.0.0.1:8000
```

Database: `belgomla` / user `belgomla` / password `belgomla_dev` (test DB: `belgomla_test`). Both have `pg_trgm` enabled.

### Tenant hosts

Tenants are resolved from the `Host` header: `{subdomain}.gomal.test`.
Add to `C:\Windows\System32\drivers\etc\hosts` (as administrator) for browser use:

```
127.0.0.1  alnoor.gomal.test
```

With curl you can pass the header instead:

```bash
curl -H "Host: alnoor.gomal.test" http://127.0.0.1:8000/api/store/tenant
```

### SPA login flow (Sanctum cookies)

1. `GET /sanctum/csrf-cookie` with `Origin: http://alnoor.gomal.test`
2. `POST /api/dashboard/auth/login` with `X-XSRF-TOKEN` header + cookies
3. `GET /api/dashboard/me`

Requests without an `Origin`/`Referer` from a tenant host get no session (`400 stateful_request_required`).

## Tenant CLI

```
php artisan tenant:create alnoor "مكتبة النور" --owner-email=owner@alnoor.test --owner-password=secret --vertical=school-books
php artisan tenant:suspend alnoor
php artisan tenant:activate alnoor
php artisan tenant:list
```

## Tests

```
php artisan test
```

Tests run against PostgreSQL `belgomla_test` (see `phpunit.xml`) and send requests to tenant hosts exactly as production does.

## Code layout

```
app/Modules/<Module>/      Models, Http/{Controllers,Middleware,Requests}, Console, services
app/Support/               ApiResponse (envelopes), ApiException (typed errors)
```

Modules talk to each other through services only, never through another module's models.
