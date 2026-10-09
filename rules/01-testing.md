# 01 — Testing Standard

Reference project: `D:\pinkary.com` (Nuno Maduro's Pinkary). Its test suite is the bar: **184 test files, ~1,100 tests, Pest, strict types, architecture tests, PHPStan level max, 100% type coverage, ~99% line coverage.** Every BelGomla feature ships with tests of that level. When unsure how to test something, open the matching file there (`tests/Http/...`, `tests/Unit/Models/...`, `tests/Arch/...`) and copy the approach.

"Feature done" means: every behaviour the feature adds has a test that fails without the code and passes with it.

---

## 1. Toolchain (already installed in `backend/`)

| Tool | Purpose | Command |
| --- | --- | --- |
| Pest 5 + pest-plugin-laravel | all tests | `php artisan test` / `vendor/bin/pest` |
| pest-plugin-type-coverage | every param/return/property typed | `vendor/bin/pest --type-coverage --min=100` |
| Larastan (PHPStan level max) | static analysis | `vendor/bin/phpstan analyse` |
| Pint (laravel preset + strict rules) | formatting | `vendor/bin/pint --test` |
| Rector | dead code, early return, type declarations | `vendor/bin/rector --dry-run` |

`composer test` runs all of them in this order: lint → types → type-coverage → unit (parallel, arch included). Run `composer lint` to auto-fix formatting before committing.

Known environment notes:

- Pest's arch plugin prints `ReflectionProperty::setAccessible() is deprecated` on PHP 8.5. Those show as "deprecated", not failures; ignore them until Pest ships a fix.
- `pest --parallel` creates `belgomla_test_1..N`; the `belgomla` role has `CREATEDB` for that.
- The Laravel arch preset is **not** used (it assumes `app/Http`, `app/Console`); its equivalents for the module layout live in `tests/Arch/*.php` and `tests/Unit/ModelConventionsTest.php`.

Line coverage: pinkary enforces `--coverage --min=98.8`. PHP 8.5 on this machine has no PCOV/Xdebug yet. Until a driver is installed the rule is enforced by review: **every class under `app/` has a test file and every public method is exercised.** The moment a coverage driver exists, add `--coverage --min=95` to `test:unit` and raise it with every module.

---

## 2. Layout

```
backend/tests/
  Pest.php                  uses TenantTestCase + RefreshDatabase for Http/Console/Unit, Storage::fake() in beforeEach
  TestCase.php              bare Laravel TestCase
  TenantTestCase.php        tenant host helpers (tenantJson, hostJson, asTenant, makeOwner, actingAsOwner)
  ArchTest.php              global presets (php, security), strict types, no debug helpers, no stock folders
  Arch/                     static rules per concept: HttpTest, ConsoleTest, ModelsTest, ServicesTest (add more per module)
  Unit/ModelConventionsTest.php   runtime model rules: factories, BelongsToTenant, datetime and money casts
  Http/<Area>/<Endpoint>Test.php    one file per endpoint group, e.g. Http/Dashboard/Settings/UpdateTest.php
  Unit/Models/<Model>Test.php
  Unit/Services/<Service>Test.php
  Unit/Middleware/<Middleware>Test.php
  Unit/Rules/, Unit/Policies/, Unit/Jobs/, Unit/Support/ ...
  Console/<Command>Test.php
  Fixtures/                 sample files (images, provider payloads)
```

Suites in `phpunit.xml`: `Http`, `Console`, `Unit`, `Arch`. Tests hit PostgreSQL `belgomla_test`, never SQLite, because the schema uses JSONB, GIN and `pg_trgm`.

---

## 3. Style (copied from pinkary)

- `<?php declare(strict_types=1);` at the top of every test file.
- Pest functions only: `test('...')`, `it('...')`, `expect()`, `arch()`. No PHPUnit classes for new tests.
- Test names are plain sentences that describe behaviour, lowercase, no "should":
  `test('a user of another tenant cannot login here')`, `it('releases the reservation when the merchant cancels')`.
  Reference the business rule when one exists: `test('BR-INV08 an adjustment cannot go below reserved')`.
- One behaviour per test. Several assertions on the same response/model are fine; two scenarios in one test are not.
- Arrange / act / assert separated by blank lines. No comments that restate the code.
- Use datasets for input variations: `->with(['unsupported action' => [...], 'malformed state' => [...]])`. Named cases, not numeric.
- Prefer `expect($x)->toBe()` chains with `->and()` over many `$this->assert*` calls. Use `assertDatabaseHas/Missing/Count` for persistence.
- No logic in tests (no `if`, no loops that hide cases, except the Arch "ensure factories" pattern). Extract helpers into `Pest.php` or `TenantTestCase`.
- Freeze time with `$this->freezeTime()` / `travelTo()` when timestamps matter. Never `sleep()`.
- Fakes, always: `Storage::fake()` (global beforeEach), `Queue::fake()`, `Notification::fake()`, `Http::fake()` + `Http::preventStrayRequests()` for every provider (WhatsApp, SMS Misr). A test that hits the network is a bug.
- Factories for every model, with states for every status (`->suspended()`, `->inactive()`, `->comingSoon()`). Arch test asserts every model has a factory.
- Models get a `to array` test listing the exact key set and count (pinkary `tests/Unit/Models/UserTest.php`) so accidental exposure of columns is caught.

---

## 4. Required tests per kind of code

### HTTP endpoint (every route in `08-api-contract.md`)

For each endpoint, a file under `tests/Http/<Surface>/<Area>/` with at least:

1. **Happy path** — exact response shape with `assertJsonPath` / `assertExactJsonStructure`; money as `"150.00"` strings; `meta` on lists.
2. **Validation** — every rule has a failing case; `assertJsonPath('error.code', 'validation_failed')` and the field present in `error.details`.
3. **Auth** — `401 unauthenticated` for guests on protected routes; `403` for the wrong status (blocked customer, disabled user, suspended tenant).
4. **Tenant isolation** — the same request with an id/slug that belongs to another tenant returns `404 not_found` (BR-T04), and nothing in the other tenant changed (`assertDatabaseHas` on the other tenant's row). This test is mandatory for every endpoint that takes an id.
5. **Cross-host session** — for authenticated routes added in a new area, one test that a session from tenant A is rejected on tenant B's host.
6. **Audit** — every mutating dashboard endpoint asserts the `audit_logs` row: action, actor, old/new values (BR-L).
7. **State machine guards** — every invalid transition returns `409` with the right code (orders, customers, products).
8. **Error envelope** — error responses never leak exception classes/messages when `app.debug` is false (one test per module is enough).

Model the "security sweep" tests from pinkary (`a guest is refused on every write endpoint`, `unknown public resources are 404 rather than 403 or 500`, `every api error status is rendered as json`): each module adds its routes to the shared sweep dataset in `tests/Http/SecuritySweepTest.php`.

### Services and pure functions

`PriceResolver`, `CheckoutService`, `ReservationService`, `CollectionService`, `MessagingService`:

- Unit tests in `tests/Unit/Services/` with datasets covering boundaries (qty = tier min − 1, = tier min, MOQ − 1, available exactly equal, MOV exactly equal).
- Transactions: a test that a failing step rolls back everything (no order row, no reservation change, no audit).
- Concurrency-sensitive code (checkout, adjustments): a test that two simultaneous attempts on the last units produce exactly one success. Use two DB connections or `DB::transaction` with `lockForUpdate` and assert final inventory; document the approach in the test.

### Models

- `to array` key-set test.
- Casts (`money` decimals as strings, `datetime` for every `*_at`, arrays for JSONB). Arch test "ensure datetime casts" from pinkary applies.
- Relationships and scopes, one test each.
- `BelongsToTenant` behaviour for every tenant-owned model: created in context → `tenant_id` set; created without context → exception; query without context → empty.

### Middleware

Test through an ad-hoc route (`Route::get('/_t', fn () => ...)->middleware(X::class)`) like pinkary's `BlockBotsTest`, covering pass and block paths and the exact status/error code.

### Form requests and rules

Every rule in `rules()` has a failing dataset case; custom `Rule` classes get unit tests with a failure recorder closure (pinkary `tests/Unit/Rules/ImageUploadTest.php`).

### Console commands

`$this->artisan(...)->assertSuccessful()` / `->assertFailed()` with `expectsOutputToContain`, plus database assertions. Reserved subdomains, duplicates and invalid input each have a case.

### Jobs, listeners, notifications

`Queue::fake()` + `assertPushed` for dispatch; `dispatchSync()` for behaviour; every outbound message asserts the row in `outbound_messages` and the fake provider call.

### Webhooks

Signature valid / invalid / replayed; payload fixtures in `tests/Fixtures/<provider>/`; status transitions on `outbound_messages`; fallback to SMS enqueued on "not a WhatsApp user".

---

## 5. Architecture tests (`tests/ArchTest.php` + `tests/Arch/`)

Mirror pinkary, adapted to the module layout. Minimum set:

```php
arch()->preset()->php();
arch()->preset()->security()->ignoring('assert');
arch('strict types')->expect('App')->toUseStrictTypes();
arch('globals')->expect(['dd', 'dump', 'ray', 'die', 'var_dump', 'sleep', 'env'])->not->toBeUsed()->ignoring('config');
arch('controllers')->expect('App\Modules\*\Http\Controllers')->toExtendNothing()->not->toBeUsed();
arch('middleware')->expect('App\Modules\*\Http\Middleware')->toHaveMethod('handle');
arch('requests')->expect('App\Modules\*\Http\Requests')->toExtend(FormRequest::class)->toHaveMethod('rules');
arch('commands')->expect('App\Modules\*\Console')->toHaveSuffix('Command')->toHaveMethod('handle');
arch('tenant-owned models use BelongsToTenant')  // every model with a tenant_id column uses the trait
arch('models have factories')                     // every model under App\Modules\*\Models has a factory
arch('modules do not reach into each other\'s models')  // Module A may use Module B only via B's services
arch('no raw tenant bypass in http')->expect('withoutTenantScope')->not->toBeUsedIn('App\Modules\*\Http');
```

Add an arch test whenever a convention is introduced (e.g. "services are final and readonly", "all money columns cast to decimal:2").

---

## 6. Workflow for a feature

1. Read the spec: the module section in `05-module-scope.md`, its rules in `02-business-rules.md`, tables in `07-domain-model.md`, endpoints in `08-api-contract.md`.
2. Write the test list first as `test('...')` stubs with `->todo()`; one stub per rule id and per endpoint case from §4. Show the list in the reply before implementing.
3. Implement; turn todos into real tests as you go.
4. `composer lint && composer test` green. Fix the code, not the test, when they disagree with the spec.
5. Curl smoke test against `php artisan serve` for anything touching middleware, sessions, cookies, uploads, or webhooks.
6. Commit.

A reply that says a feature is done must include the test count delta ("+38 tests, 102 total, all green") and the list of rule ids covered.

---

## 7. Anti-patterns that fail review

- A test that only asserts status 200.
- `RefreshDatabase` missing on a test that writes.
- Creating tenant-owned rows without `asTenant()` / context (the trait will throw; do not work around it with `withoutTenantScope`).
- Hard-coded ids (`/api/dashboard/products/1`); use the created model's id.
- Asserting Arabic messages by substring in many tests; assert `error.code`, and assert the message text in exactly one test per message.
- Sleeping, real HTTP, real Redis-dependent behaviour without a fake, random data without a seed (`fake()->seed(1234)` in `Pest.php` when determinism matters).
- Skipped or incomplete tests left in the suite (`->skip()` only with a linked open decision id).
