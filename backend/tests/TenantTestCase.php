<?php

declare(strict_types=1);

namespace Tests;

use App\Modules\Catalog\Enums\ProductStatus;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\Sku;
use App\Modules\Catalog\Models\Unit;
use App\Modules\Customers\Models\Cart;
use App\Modules\Customers\Models\CartItem;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\CustomerAddress;
use App\Modules\Orders\Actions\Checkout;
use App\Modules\Orders\Data\CheckoutData;
use App\Modules\Orders\Models\Order;
use App\Modules\Pricing\Models\SkuPriceTier;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Models\TenantUser;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Base class for tests that talk to the API as a tenant.
 * Requests are sent to the tenant's host with an Origin header, exactly like the Next.js SPA.
 */
abstract class TenantTestCase extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;

    /** @var array{category: Category, carton: Unit, piece: Unit}|null */
    private ?array $catalogBasics = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create(['subdomain' => 'alnoor', 'name' => 'مكتبة النور']);
    }

    /**
     * One category and two units on the default tenant, created once per test.
     *
     * @return array{category: Category, carton: Unit, piece: Unit}
     */
    protected function catalogBasics(): array
    {
        return $this->catalogBasics ??= $this->inTenant(fn (): array => [
            'category' => Category::factory()->create(['name' => 'إعدادي']),
            'carton' => Unit::factory()->create(['name' => 'كرتونة']),
            'piece' => Unit::factory()->create(['name' => 'قطعة']),
        ]);
    }

    /**
     * A valid product create/update body (08-api-contract.md §6); overrides merge recursively.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function productPayload(array $overrides = []): array
    {
        $basics = $this->catalogBasics();

        $merged = array_replace_recursive([
            'name' => 'الأضواء علوم أولى إعدادي ترم أول',
            'category_id' => $basics['category']->id,
            'description' => 'كتاب العلوم',
            'custom_fields' => [],
            'sku' => [
                'code' => 'ADW-SCI-P1-T1',
                'selling_unit_id' => $basics['carton']->id,
                'base_unit_id' => $basics['piece']->id,
                'conversion_factor' => 20,
                'cover_price' => '120.00',
                'base_price' => '90.00',
                'base_price_percent' => null,
                'moq' => 5,
            ],
        ], $overrides);

        $payload = [];

        foreach ($merged as $key => $value) {
            $payload[(string) $key] = $value;
        }

        return $payload;
    }

    protected function hostOf(Tenant $tenant): string
    {
        return $tenant->subdomain.'.'.config()->string('tenancy.base_domain');
    }

    /**
     * Send a JSON request to a tenant's host (defaults to the test tenant).
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $headers
     * @return TestResponse<Response>
     */
    protected function tenantJson(string $method, string $uri, array $data = [], ?Tenant $tenant = null, array $headers = []): TestResponse
    {
        return $this->hostJson($this->hostOf($tenant ?? $this->tenant), $method, $uri, $data, $headers);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $headers
     * @return TestResponse<Response>
     */
    protected function hostJson(string $host, string $method, string $uri, array $data = [], array $headers = []): TestResponse
    {
        // Each real request starts with an empty tenant context; mirror that so middleware-order bugs show up.
        app(TenantContext::class)->clear();

        // Symfony's Request::create() derives HTTP_HOST from the URL, so the host must be in the URL itself.
        return $this->json($method, 'http://'.$host.$uri, $data, $headers + ['Origin' => 'http://'.$host]);
    }

    /**
     * Run a callback inside a tenant's context (BelongsToTenant fills tenant_id).
     *
     * @template TReturn
     *
     * @param  callable(Tenant): TReturn  $callback
     * @return TReturn
     */
    protected function asTenant(Tenant $tenant, callable $callback): mixed
    {
        return app(TenantContext::class)->runAs($tenant, $callback);
    }

    /**
     * asTenant() for the default test tenant, or another one when given.
     *
     * @template TReturn
     *
     * @param  callable(Tenant): TReturn  $callback
     * @return TReturn
     */
    protected function inTenant(callable $callback, ?Tenant $tenant = null): mixed
    {
        return $this->asTenant($tenant ?? $this->tenant, $callback);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function makeOwner(?Tenant $tenant = null, array $attributes = []): TenantUser
    {
        $tenant ??= $this->tenant;

        // refresh() loads DB defaults (remember_token, timestamps) so strict-mode attribute access never throws.
        return $this->asTenant($tenant, fn (): TenantUser => TenantUser::factory()->create($attributes)->refresh());
    }

    /** Authenticate a dashboard user for subsequent requests on their tenant host. */
    protected function actingAsOwner(?TenantUser $user = null): TenantUser
    {
        $user ??= $this->makeOwner();
        $this->actingAs($user, 'tenant_user');

        return $user;
    }

    /**
     * A storefront customer; active with the password `password` unless the attributes say otherwise.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function makeCustomer(array $attributes = [], ?Tenant $tenant = null): Customer
    {
        return $this->asTenant($tenant ?? $this->tenant, fn (): Customer => Customer::factory()->create($attributes)->refresh());
    }

    /** Authenticate a storefront customer for subsequent requests on their tenant host. */
    protected function actingAsCustomer(?Customer $customer = null): Customer
    {
        $customer ??= $this->makeCustomer();
        $this->actingAs($customer, 'customer');

        return $customer;
    }

    protected function otherTenant(string $subdomain = 'other'): Tenant
    {
        return Tenant::factory()->create(['subdomain' => $subdomain, 'name' => 'متجر آخر']);
    }

    /**
     * An orderable SKU: sold in cartons of 20 pieces, MOQ 5, base price 90.00, tier 10+ → 85.00, with
     * `$onHand` pieces in stock (default 400 = 20 cartons).
     *
     * @param  array<string, mixed>  $attributes  SKU overrides
     */
    protected function orderableSku(array $attributes = [], int $onHand = 400, ProductStatus $status = ProductStatus::Active, ?Tenant $tenant = null, string $name = 'الأضواء علوم'): Sku
    {
        return $this->asTenant($tenant ?? $this->tenant, function () use ($attributes, $onHand, $status, $name): Sku {
            $carton = Unit::query()->firstOrCreate(['name' => 'كرتونة']);
            $sku = Product::factory()
                ->state(['name' => $name, 'status' => $status])
                ->withSku([
                    'selling_unit_id' => $carton->id,
                    'conversion_factor' => 20,
                    'moq' => 5,
                    'base_price' => '90.00',
                    ...$attributes,
                ], onHand: $onHand)
                ->create()
                ->sku()
                ->sole();
            SkuPriceTier::factory()->create(['sku_id' => $sku->id, 'min_quantity' => 10, 'unit_price' => '85.00']);

            return $sku->refresh();
        });
    }

    /**
     * Puts lines in a customer's cart, as `[sku_id => quantity]`.
     *
     * @param  array<int, int>  $lines
     */
    protected function fillCart(Customer $customer, array $lines): void
    {
        $this->asTenant(Tenant::query()->findOrFail($customer->tenant_id), function () use ($customer, $lines): void {
            $cart = Cart::query()->firstOrCreate(['customer_id' => $customer->id]);

            foreach ($lines as $skuId => $quantity) {
                CartItem::query()->updateOrCreate(['cart_id' => $cart->id, 'sku_id' => $skuId], ['quantity' => $quantity]);
            }
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function addressOf(Customer $customer, array $attributes = []): CustomerAddress
    {
        return $this->asTenant(
            Tenant::query()->findOrFail($customer->tenant_id),
            fn (): CustomerAddress => CustomerAddress::factory()->for($customer)->default()->create($attributes)->refresh(),
        );
    }

    /**
     * A real pending order placed through checkout (stock reserved, history, notification): the cart is filled
     * with `[sku_id => quantity]` and checked out to the customer's first address, created when there is none.
     *
     * @param  array<int, int>  $lines
     */
    protected function placeOrder(Customer $customer, array $lines, ?string $note = null): Order
    {
        $this->fillCart($customer, $lines);
        $tenant = Tenant::query()->findOrFail($customer->tenant_id);
        $address = $this->asTenant($tenant, fn (): ?CustomerAddress => CustomerAddress::query()->where('customer_id', $customer->id)->first())
            ?? $this->addressOf($customer);

        return $this->asTenant($tenant, fn (): Order => app(Checkout::class)->handle($customer, new CheckoutData($address->id, $note))->refresh());
    }
}
