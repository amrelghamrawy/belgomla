# 02 — Architecture & Code Structure

Reference project: `D:\pinkary.com`. Its style is the standard here, adapted to a **modular monolith**. The goal: every file in this repo looks like it was written by the same person. When a situation is not covered below, open the matching class in pinkary and copy its shape, then add the rule here.

Design pattern, decided once: **Thin HTTP layer → Actions (write) / Queries (read) → Models.** No repositories, no generic "manager" classes, no domain events in V1.

---

## 1. Module layout

```
app/
  Modules/<Module>/
    Actions/        write use-cases                 CreateProduct, ReserveStock, CancelOrder
    Queries/        read use-cases                  ProductListQuery, OutstandingBalanceQuery
    Services/       computations and integrations   PriceResolver, Messenger, StockLedger
    Data/           immutable input DTOs            ProductData, CheckoutData
    Enums/          backed string enums             OrderStatus, ProductStatus
    Models/         Eloquent models (+ Scopes/)
    Policies/       authorization for customer-facing and transition rules
    Observers/      model lifecycle side effects (slugs, cache)
    Jobs/           queued work
    Notifications/  in-app notifications
    Rules/          custom validation rules
    Exceptions/     typed failures (extend ApiException)
    Contracts/      interfaces (only when two implementations exist or are planned)
    Console/        artisan commands (*Command)
    Http/
      Controllers/  final readonly, no base class
      Requests/     FormRequests with typed accessors
      Resources/    JsonResource output shapes
      Middleware/
  Support/          global, framework-free helpers (final, static)   Money, ApiResponse, ApiException
  Providers/        AppServiceProvider only (plus one per module if it needs bindings)
```

Stock Laravel folders (`app/Http`, `app/Models`, `app/Console`) are **never** used; an arch test fails if they exist.

Cross-module rule: module A may call module B only through B's `Actions`, `Queries`, `Services` or `Enums`. Never B's models, requests, or resources. Arch tests enforce this per module as modules are added.

Seeders (`database/seeders`) are dev tooling like factories, not a module: they may read any module's models (to find what to act on, or to type what an action returned) but write only through the modules' actions, like a merchant or customer would. A write no action covers (a demo customer accepting an invitation without an OTP) is a `forceFill` with a comment saying which action it stands for. The model-boundary arch tests list `Database\Seeders` next to `Database\Factories`.

---

## 2. Which class to write (decision table)

| You need to…                                                         | Write a…        | Folder        | Shape                                              |
| -------------------------------------------------------------------- | --------------- | ------------- | -------------------------------------------------- |
| Change state (create/update/delete, transition, reserve, record)     | **Action**      | `Actions/`    | `final readonly class VerbNoun { handle(...) }`    |
| Read a list/page/report/aggregate for HTTP or CLI                    | **Query**       | `Queries/`    | `final readonly class NounQuery { builder()/paginate()/get() }` |
| Compute or integrate, reused by several actions (pricing, messaging) | **Service**     | `Services/`   | `final readonly class Noun { named methods }`      |
| Pure function with no framework or DB access                         | **Support**     | `app/Support` | `final class Noun { public static … }`             |
| Carry validated input into an action (3+ fields)                     | **Data**        | `Data/`       | `final readonly class NounData { __construct(public …) }` |
| Represent a closed set of values (status, role, type)                | **Enum**        | `Enums/`      | `enum Noun: string { … label(), toArray() }`       |
| Validate HTTP input                                                  | **Request**     | `Http/Requests/` | `final class VerbNounRequest extends FormRequest` |
| Shape HTTP output                                                    | **Resource**    | `Http/Resources/` | `final class NounResource extends JsonResource` |
| Decide if an actor may act on a model                                | **Policy**      | `Policies/`   | `final readonly class NounPolicy { ability(...) }` |
| React to a model being created/updated/deleted                       | **Observer**    | `Observers/`  | `#[ObservedBy]` on the model                        |
| Run later / off the request                                          | **Job**         | `Jobs/`       | `final class VerbNoun implements ShouldQueue`      |
| Reusable field validation                                            | **Rule**        | `Rules/`      | `final readonly class Noun implements ValidationRule` |
| Signal a business failure to the API                                 | **Exception**   | `Exceptions/` | `final class NounException extends ApiException`   |
| Share behaviour across models/requests (cross-cutting only)          | **Trait**       | `Concerns/`   | `trait NounBehaviour`                              |

Hard lines:

- **Action vs Service**: writes to the database → Action. Computes or talks to a provider and is called *by* actions → Service. A class that does both is split. One named exception: `Inventory\Services\StockLedger` writes `sku_inventory` and `inventory_movements`, because every stock change in every module (adjustments, checkout, transitions) must go through the same locking code; Inventory's actions and Orders call it, and an arch test makes it the only writer. Second named exception: `Messaging\Services\Messenger` creates the `outbound_messages` row, because it is the single entry point every module uses to send WhatsApp/SMS (§8); every later change to that row goes through `Messaging\Actions\RecordDeliveryStatus`.
- **Service vs Support**: needs the container, a facade, DB, HTTP, config → Service (injected). Pure input→output → Support (static). Never name anything `Helper`, `Manager`, `Util`, `Handler`.
- **Action vs Observer**: explicit business steps (reserve stock, write audit, notify merchant) are called from the action, in order, in one transaction. Observers only keep model invariants (slug, materialized path, cache busting). If you need to "react" across modules, call the other module's action explicitly; do not add Events/Listeners.
- **Query vs Model scope**: a scope is a reusable filter on one model (`Scopes/Active.php`, invokable class). A Query composes scopes, joins, facets and pagination for one screen or report.
- **DTO vs array**: ≤2 scalar inputs → method parameters. 3+ fields, or anything nested → a `Data` class built by `NounData::fromRequest($request)`. Actions never receive `$request`.
- **Policy**: required for every customer-facing model operation and for every state transition (`cancel`, `transition`, `void`). Dashboard CRUD on tenant-owned data needs no policy; the tenant scope plus `auth:tenant_user` is the authorization. Policies are auto-discovered: `Modules\Orders\Policies\OrderPolicy` for `Modules\Orders\Models\Order` is registered in the module provider via `Gate::policy`.

---

## 3. Class shape rules

Every class under `app/` is `final` unless it is an explicitly listed abstract base (`TenantStatusCommand`). Classes without mutable state are `readonly`:

| Kind        | Modifiers                 | Single public entry point | May depend on                            |
| ----------- | ------------------------- | ------------------------- | ---------------------------------------- |
| Action      | `final readonly`          | `handle()`                | Models, Services, Queries (read a selection, e.g. bulk by filter), other Actions, Audit |
| Query       | `final readonly`          | `builder()` / `paginate()` / `get()` | Models, Scopes                |
| Service     | `final readonly`          | named methods             | Config, Http, Cache, Models (read)       |
| Controller  | `final readonly`          | REST methods              | Requests, Actions, Queries, Resources, Gate |
| Policy      | `final readonly`          | ability methods           | Models only                              |
| Observer    | `final readonly`          | lifecycle methods         | Actions, Jobs, invariant Services (slug, cache version) |
| Request     | `final`                   | `rules()` + typed accessors | Rules, Enums                           |
| Resource    | `final`                   | `toArray(Request)`        | Models, Support                          |
| Model       | `final`                   | relations, casts, accessors, scopes | nothing from Actions/Services  |
| Job         | `final`                   | `handle()`                | Actions, Services                        |
| Rule        | `final readonly`          | `validate()`              | nothing                                  |
| Data        | `final readonly`          | constructor + `fromRequest()` | Enums                                |
| Enum        | backed `string`           | `label()`, `toArray()`    | nothing                                  |
| Support     | `final`, static only      | named statics             | nothing                                  |

Conventions copied from pinkary:

- `declare(strict_types=1);` everywhere. PHPStan level max, 100% type coverage. Docblocks for every array shape and generic (`@return Builder<Product>`, `@param list<int> $ids`).
- Constructor promotion with `private readonly` dependencies. No setters. No static state except enum/const.
- One class per file, class name = file name, namespace = path.
- Methods are short; a `handle()` that needs sections is split into private methods or further actions.
- No `abort()` in business code: throw a typed exception (see §7). `abort()` only in middleware edge cases.
- No `env()` outside `config/`. No `dd/dump/sleep`. No global helper functions.
- Dates: `CarbonImmutable` (`Date::use(CarbonImmutable::class)` in the provider); model date properties are typed `CarbonImmutable`.
- Models are unguarded (`Model::unguard()`); input safety comes from Requests + Data classes, never from `$fillable`. `Model::shouldBeStrict()` outside production. `DB::prohibitDestructiveCommands()` in production.

---

## 4. HTTP layer

### Controllers

- `final readonly`, no base class, no constructor dependencies: inject Actions/Queries/Services **per method**.
- RESTful method names only: `index, store, show, update, destroy`. Anything else is its own controller named after the sub-resource or verb: `OrderTransitionController@store`, `OrderCancelController@store`, `ProductDuplicateController@store`, `PricingBulkController@store`.
- A controller method does exactly: authorize → read typed input from the Request → call one Action or Query → return a Resource or `ApiResponse`. No branching on business rules, no queries, no transactions.
- Module route files: `routes/api/store/<module>.php`, `routes/api/dashboard/<module>.php`, `routes/api/webhooks.php`, required from `routes/api.php`. Every route is named (`store.products.index`, `dashboard.orders.transition`). Ids use `whereNumber`.

### Requests

- One per endpoint that takes input: `StoreProductRequest`, `UpdateSettingsRequest`, `TransitionOrderRequest`.
- `rules()` fully typed; Arabic `messages()` only for rules whose default message is misleading.
- **Typed accessors** for everything the controller/action needs (`name(): string`, `perPage(): int`, `status(): OrderStatus`). Controllers never call `$request->input()` / `validated()` directly, and never pass the request to an action.
- Shared pagination/sorting lives in `App\Support\Http\PaginatedRequest` (base, allowed to be extended) with `perPage()`, `page()`, `sort()`.

### Resources

- Every response body is a `JsonResource` (`ProductResource`, `OrderResource`, `TenantSettingsResource`). Models never have `toApiArray()`.
- `@property-read Model $resource` docblock; `toArray()` builds an explicit key list, money as `"150.00"` strings, dates ISO-8601 UTC, nested resources for relations.
- Collections: `ProductResource::collection($paginator)`; the paginated envelope is Laravel's (`data`, `links`, `meta`). `08-api-contract.md` §0 follows this.
- Conditional fields (`require_login_for_prices`) use `$this->when(...)`, never a second resource class.
- Errors keep the `ApiResponse::error()` envelope.

---

## 5. Actions, Queries, Data

```php
final readonly class ReserveStock
{
    public function __construct(private AuditLogger $audit) {}

    public function handle(Sku $sku, int $quantityBase, Order $order): void
    {
        // lockForUpdate, check, update, audit — all inside the caller's transaction
    }
}
```

- Name = `Verb` + `Noun` in the module's language: `CreateProduct`, `UpdateProductPricing`, `TransitionOrder`, `RecordCollection`, `VoidCollection`, `InviteCustomer`. No `Action` suffix.
- An Action owns its transaction when it is the top-level use-case (`Checkout`), and joins the caller's transaction when it is a step (`ReserveStock`). Document which one in the class docblock.
- Audit records are written by the Action that changes the data (never by observers), using `AuditLogger`.
- Queries return `Builder`, `LengthAwarePaginator` or a typed `Collection`; they never mutate. Facet counting, search, reports live here.
- `Data` classes are immutable: promoted `public readonly` properties, a `fromRequest(FormRequest $request): self` named constructor, and nothing else. Enums are used for every closed-set field.

---

## 6. Models, Enums, Scopes, Observers

- Model = schema mirror: `@property` docblock for every column, `casts()`, relations with generics, accessors, `newFactory()`. **No business logic** and no calls into Actions/Services.
- Every tenant-owned model uses `BelongsToTenant` (Tenancy module). Platform models (`Tenant`, `TenantSetting`) do not.
- Status/role/type columns are **enums**: `enum OrderStatus: string` with cases in `snake_case` values, `label(): string` (Arabic), `toArray(): array<string,string>`, and transition helpers where relevant (`canTransitionTo()`). Cast on the model. No string constants on models.
- Scopes are invokable classes in `Models/Scopes/` (`Active`, `ForCustomer`) used as `->tap(new Active)` or `->where(new Active)`; the tenant scope is the only global scope.
- Observers via `#[ObservedBy(ProductObserver::class)]`; only invariants (slug, path, cache). No notifications, no audit, no cross-module writes in observers.

---

## 7. Errors

- `App\Support\ApiException` is the base for every error the API returns on purpose. Modules add `final` subclasses with named constructors that fix status + code: `InsufficientStock::for($sku, $available)` → `422 insufficient_stock`, `InvalidOrderTransition::from($a, $b)` → `409 invalid_transition`.
- Actions throw these; controllers never catch them; `bootstrap/app.php` renders the envelope. Arabic messages live in the exception, one place.
- Unexpected failures are plain `RuntimeException`s and become `500 server_error`.

---

## 8. Jobs, Notifications, Messaging

- Jobs are `final class VerbNoun implements ShouldQueue` with `Queueable`, constructor carries ids or models, `handle()` takes dependencies by injection, logic delegated to an Action/Service. Provide `dispatchFor(...)` statics when the call site needs clarity.
- In-app notifications are rows in our own `notifications` table (`Orders\Models\MerchantNotification`, 07 §9), written by an Action (`NotifyMerchants`) inside the transaction of the change they report — not Laravel `Notification` classes (decided in Task 6: the dashboard reads them per tenant, and a write belongs in the action's transaction).
- `Idempotency-Key` is handled by the `idempotent` route middleware (`App\Support\Http\Middleware\IdempotencyKey`, D55), listed after `auth:*`; add it to every new retry-sensitive `POST`.
- Outbound WhatsApp/SMS go only through `Messaging\Services\Messenger`; drivers implement `Messaging\Contracts\MessageDriver`. Nothing else may call a provider.

---

## 9. Naming cheat-sheet

| Thing            | Pattern                               | Examples                                         |
| ---------------- | ------------------------------------- | ------------------------------------------------ |
| Action           | `VerbNoun`                            | `CreateProduct`, `ReleaseReservation`            |
| Query            | `NounQuery` / `NounFeed`              | `ProductListQuery`, `LowStockQuery`              |
| Service          | what it is, no suffix                 | `PriceResolver`, `Messenger`, `FacetCounter`     |
| Data             | `NounData`                            | `ProductData`, `CheckoutData`                    |
| Enum             | `NounKind`                            | `OrderStatus`, `CollectionMethod`, `Vertical`    |
| Request          | `VerbNounRequest`                     | `StoreProductRequest`, `TransitionOrderRequest`  |
| Resource         | `NounResource`                        | `ProductResource`                                |
| Controller       | `NounController` / `NounVerbController` | `ProductController`, `OrderCancelController`   |
| Policy           | `NounPolicy`                          | `OrderPolicy`                                    |
| Exception        | `ProblemNoun` (no suffix)             | `InsufficientStock`, `InvalidOrderTransition`    |
| Job              | `VerbNoun`                            | `SendOtp`, `RecomputeFacetCounts`                |
| Rule             | `Noun`                                | `EgyptianPhone`, `HexColor`                      |
| Scope            | `Adjective` / `ForNoun`               | `Active`, `ForCustomer`                          |
| Command          | `VerbNounCommand`                     | `TenantCreateCommand`                            |
| Test             | sentence                              | `test('BR-INV08 an adjustment cannot go below reserved')` |

---

## 10. Checklist before a module is "done"

1. Every folder above that the module needs exists with the right shapes; nothing lives in a folder it does not belong to.
2. Arch tests in `tests/Arch/` cover the module's namespaces (final/readonly, suffixes, `handle()`, dependency directions).
3. No controller contains an `if` on business state, a query builder, or a transaction.
4. No model contains a method that writes to another table.
5. Enums replace every status string; Resources replace every `toArray`-style method; Data classes replace every `array $attributes` action parameter.
6. `rules/01-testing.md` satisfied.
