<?php

declare(strict_types=1);

namespace Tests;

use App\Modules\Catalog\Actions\CreateBundle;
use App\Modules\Catalog\Actions\CreateProduct;
use App\Modules\Catalog\Data\BundleComponentData;
use App\Modules\Catalog\Data\BundleData;
use App\Modules\Catalog\Data\ProductData;
use App\Modules\Catalog\Data\ProductOptionData;
use App\Modules\Catalog\Data\ProductOptionValueData;
use App\Modules\Catalog\Data\SkuData;
use App\Modules\Catalog\Data\SkuUnitData;
use App\Modules\Catalog\Enums\CategoryKind;
use App\Modules\Catalog\Enums\ProductStatus;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductFieldDefinition;
use App\Modules\Catalog\Models\ProductFieldOption;
use App\Modules\Catalog\Models\Sku;
use App\Modules\Catalog\Models\SkuUnit;
use App\Modules\Catalog\Models\Unit;
use App\Modules\Catalog\Services\VariantGenerator;
use App\Modules\Customers\Enums\CartLineKind;
use App\Modules\Customers\Models\Cart;
use App\Modules\Customers\Models\CartItem;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\CustomerAddress;
use App\Modules\Orders\Actions\Checkout;
use App\Modules\Orders\Actions\PlacePreorder;
use App\Modules\Orders\Data\CheckoutData;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Pricing\Data\BundlePricingData;
use App\Modules\Pricing\Data\SkuPricingData;
use App\Modules\Pricing\Enums\BundlePriceMode;
use App\Modules\Pricing\Models\SkuPriceTier;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Models\TenantUser;
use App\Modules\Tenancy\TenantContext;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use LogicException;
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

    /** @var array{root: Category, grade: Category, carton: Unit, piece: Unit, publisher: ProductFieldDefinition, adwaa: ProductFieldOption, moallem: ProductFieldOption, brand: ProductFieldDefinition}|null */
    private ?array $importBasics = null;

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
     * V2 Task 06: the catalog a file is imported into, created once per test: the books section
     * "كتب خارجية > أولى إعدادي", the units كرتونة and قطعة, a books-only select field `publisher` (الأضواء، المعلم)
     * and a stationery-only text field `brand`.
     *
     * @return array{root: Category, grade: Category, carton: Unit, piece: Unit, publisher: ProductFieldDefinition, adwaa: ProductFieldOption, moallem: ProductFieldOption, brand: ProductFieldDefinition}
     */
    protected function importBasics(): array
    {
        return $this->importBasics ??= $this->inTenant(function (): array {
            $root = Category::factory()->books()->create(['name' => 'كتب خارجية']);
            $publisher = ProductFieldDefinition::factory()->select()->forKind(CategoryKind::Books)->create(['key' => 'publisher', 'label' => 'الشركة', 'sort_order' => 1]);

            return [
                'root' => $root,
                'grade' => Category::factory()->childOf($root)->create(['name' => 'أولى إعدادي'])->refresh(),
                'carton' => Unit::factory()->create(['name' => 'كرتونة']),
                'piece' => Unit::factory()->create(['name' => 'قطعة']),
                'publisher' => $publisher,
                'adwaa' => ProductFieldOption::factory()->create(['definition_id' => $publisher->id, 'label' => 'الأضواء']),
                'moallem' => ProductFieldOption::factory()->create(['definition_id' => $publisher->id, 'label' => 'المعلم']),
                'brand' => ProductFieldDefinition::factory()->forKind(CategoryKind::Stationery)->create(['key' => 'brand', 'label' => 'الماركة', 'sort_order' => 2]),
            ];
        });
    }

    /**
     * A valid row of the catalog file (format v2, by column key: one row per SKU unit) for a new book in
     * "كتب خارجية > أولى إعدادي", sold by the carton of 20; overrides replace cells, a null removes the column.
     *
     * @param  array<string, string|null>  $overrides
     * @return array<string, string>
     */
    protected function importRow(array $overrides = []): array
    {
        $this->importBasics();

        $row = [
            'sku_code' => 'ADW-SCI-1',
            'product_name' => 'الأضواء علوم أولى إعدادي',
            'category_path' => 'كتب خارجية > أولى إعدادي',
            'status' => 'نشط',
            'description' => 'كتاب العلوم',
            'base_unit' => 'قطعة',
            'unit' => 'كرتونة',
            'unit_is_default' => 'نعم',
            'conversion_factor' => '20',
            'cover_price' => '120',
            'base_price' => '90',
            'base_price_percent' => '',
            'moq' => '5',
            'tier_1_min' => '10',
            'tier_1_price' => '85',
            'tier_2_min' => '',
            'tier_2_price' => '',
            'on_hand' => '400',
            'field:publisher' => 'الأضواء',
            'field:brand' => '',
        ];

        foreach ($overrides as $column => $value) {
            if ($value === null) {
                unset($row[$column]);
            } else {
                $row[$column] = $value;
            }
        }

        return $row;
    }

    /**
     * V2 Task 06: the product importRow() describes, already in the catalog: so importing importRow() unchanged is an
     * update that changes nothing. Overrides go to the SKU.
     *
     * @param  array<string, mixed>  $sku
     */
    protected function importedProduct(array $sku = [], int $onHand = 400, ProductStatus $status = ProductStatus::Active): Product
    {
        $basics = $this->importBasics();

        return $this->inTenant(function () use ($basics, $sku, $onHand, $status): Product {
            $product = Product::factory()->withSku([
                'code' => 'ADW-SCI-1',
                'selling_unit_id' => $basics['carton']->id,
                'base_unit_id' => $basics['piece']->id,
                'conversion_factor' => 20,
                'cover_price' => '120.00',
                'base_price' => '90.00',
                'moq' => 5,
                ...$sku,
            ], onHand: $onHand)->create([
                'name' => 'الأضواء علوم أولى إعدادي',
                'category_id' => $basics['grade']->id,
                'description' => 'كتاب العلوم',
                'status' => $status,
                'custom_fields' => ['publisher' => $basics['adwaa']->id],
            ]);
            SkuPriceTier::factory()->create(['sku_id' => $product->skus()->sole()->id, 'min_quantity' => 10, 'unit_price' => '85.00']);

            return $product->refresh()->load('skus.activeUnits.tiers');
        });
    }

    /**
     * V2 Task 15 (D93): a bundle «باقة أولى إعدادي» (code SET-1, sold by the piece, MOQ 2) of `$quantity` of the given
     * product's default unit, at the components' sum less 10 %, made through CreateBundle in importBasics()' grade.
     */
    protected function importedBundle(Product $component, int $quantity = 2, string $code = 'SET-1'): Product
    {
        $basics = $this->importBasics();

        return $this->inTenant(fn (): Product => app(CreateBundle::class)->handle(new BundleData(
            name: 'باقة أولى إعدادي',
            categoryId: $basics['grade']->id,
            code: $code,
            unitId: $basics['piece']->id,
            components: [new BundleComponentData($this->defaultUnitId($component->skus()->firstOrFail()), $quantity)],
            pricing: new BundlePricingData(BundlePriceMode::PercentOffSum, '10.00'),
            moq: 2,
        )));
    }

    /**
     * V2 Task 15 (BR-P15, D93): a bundle «طقم الصف» made through CreateBundle of `[sku_id => quantity]` (each SKU's default
     * unit), sold by the piece, priced `$mode` at `$value` (fixed 300.00 by default).
     *
     * @param  array<int, int>  $components
     */
    protected function gradeSet(array $components, BundlePriceMode $mode = BundlePriceMode::Fixed, string $value = '300.00', int $moq = 1, string $code = 'SET-1', ?ProductStatus $status = null, ?Tenant $tenant = null): Product
    {
        return $this->inTenant(function () use ($components, $mode, $value, $moq, $code, $status): Product {
            $piece = Unit::query()->firstOrCreate(['name' => 'قطعة']);
            $category = Category::query()->first() ?? Category::factory()->create(['name' => 'أولى إعدادي']);

            return app(CreateBundle::class)->handle(new BundleData(
                name: 'طقم الصف',
                categoryId: $category->id,
                code: $code,
                unitId: $piece->id,
                components: array_map(fn (int $skuId, int $quantity): BundleComponentData => new BundleComponentData($this->defaultUnitId($skuId), $quantity), array_keys($components), $components) ?: throw new LogicException('A bundle has a component.'),
                pricing: new BundlePricingData($mode, $value),
                moq: $moq,
                status: $status,
            ))->refresh();
        }, $tenant);
    }

    /** The SKU of a bundle (D93: exactly one). */
    protected function bundleSku(Product $bundle): Sku
    {
        return $this->inTenant(fn (): Sku => Sku::query()->where('product_id', $bundle->id)->sole(), Tenant::query()->findOrFail($bundle->tenant_id));
    }

    /**
     * V2 Task 15 (D93): an order of `$bundles` of a bundle at `$price` each, as checkout snapshots it: the bundle line
     * (priced, holding nothing) and a child line per component (`[sku_unit_id => quantity in one bundle]`). With
     * `$delivered` the order is delivered at `$deliveredAt` with that many bundles gone out (the children follow, the
     * bundle line charged for them); without it the order stays as `$order` makes it (pending by default).
     *
     * @param  array<int, int>  $components
     * @param  array<string, mixed>  $order  Order attributes
     */
    protected function bundleOrder(Sku $bundle, array $components, int $bundles = 1, string $price = '100.00', ?int $delivered = null, ?string $deliveredAt = null, array $order = [], ?Tenant $tenant = null): Order
    {
        return $this->inTenant(function () use ($bundle, $components, $bundles, $price, $delivered, $deliveredAt, $order): Order {
            $state = $delivered === null ? $order : ['status' => 'delivered', 'confirmed_at' => now(), 'delivered_at' => $deliveredAt ?? now(), ...$order];
            $placed = Order::factory()->create($state);
            $line = OrderItem::factory()->for($placed)->create([
                'sku_id' => $bundle->id, 'is_bundle' => true, 'reserved' => false, 'quantity' => $bundles, 'unit_price' => $price,
                ...($delivered === null ? [] : ['delivered_quantity' => $delivered, 'line_total' => Money::times($price, $delivered)]),
            ]);

            foreach ($components as $unitId => $quantity) {
                $unit = SkuUnit::query()->findOrFail($unitId);
                OrderItem::factory()->for($placed)->create([
                    'sku_id' => $unit->sku_id, 'sku_unit_id' => $unit->id, 'parent_item_id' => $line->id, 'quantity' => $quantity * $bundles, 'unit_price' => '10.00',
                    ...($delivered === null ? [] : ['delivered_quantity' => $quantity * $delivered]),
                ]);
            }

            $placed->forceFill(['items_total' => $line->line_total, 'order_total' => $line->line_total])->save();

            return $placed;
        }, $tenant);
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
                'base_unit_id' => $basics['piece']->id,
                'units' => [[
                    'unit_id' => $basics['carton']->id,
                    'conversion_factor' => 20,
                    'cover_price' => '120.00',
                    'base_price' => '90.00',
                    'base_price_percent' => null,
                    'moq' => 5,
                ]],
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
                ->skus()
                ->sole();
            SkuPriceTier::factory()->create(['sku_id' => $sku->id, 'min_quantity' => 10, 'unit_price' => '85.00']);

            return $sku->refresh();
        });
    }

    /**
     * Puts lines in a customer's cart, as `[sku_id => quantity]` in each SKU's default unit (D91).
     *
     * @param  array<int, int>  $lines
     */
    protected function fillCart(Customer $customer, array $lines): void
    {
        $this->asTenant(Tenant::query()->findOrFail($customer->tenant_id), function () use ($customer, $lines): void {
            $cart = Cart::query()->firstOrCreate(['customer_id' => $customer->id]);

            foreach ($lines as $skuId => $quantity) {
                CartItem::query()->updateOrCreate(['cart_id' => $cart->id, 'sku_unit_id' => $this->defaultUnitId($skuId), 'kind' => CartLineKind::Order], ['sku_id' => $skuId, 'quantity' => $quantity]);
            }
        });
    }

    /**
     * BR-P14 (V2 Task 14): a pen with variants, made through CreateProduct: one option «اللون» (and «المقاس» when sizes are
     * given), one SKU per combination coded `PEN-{n}` in combination order, each sold by the piece at its price (default
     * 10.00) with its opening stock (default 50). `$prices` / `$stock` are keyed by the variant label («أزرق / M»).
     *
     * @param  list<string>  $colours
     * @param  list<string>  $sizes
     * @param  array<string, string>  $prices
     * @param  array<string, int>  $stock
     */
    protected function variantProduct(array $colours = ['أزرق', 'أسود', 'أحمر'], array $sizes = [], array $prices = [], array $stock = [], string $name = 'قلم جاف', string $code = 'PEN', ?Tenant $tenant = null): Product
    {
        return $this->asTenant($tenant ?? $this->tenant, function () use ($colours, $sizes, $prices, $stock, $name, $code): Product {
            $piece = Unit::query()->firstOrCreate(['name' => 'قطعة']);
            $category = Category::factory()->kind(CategoryKind::Stationery)->create(['name' => 'أدوات']);
            $options = [new ProductOptionData(null, 'اللون', array_map(fn (string $value): ProductOptionValueData => new ProductOptionValueData(null, $value), $colours))];

            if ($sizes !== []) {
                $options[] = new ProductOptionData(null, 'المقاس', array_map(fn (string $value): ProductOptionValueData => new ProductOptionValueData(null, $value), $sizes));
            }

            $skus = [];

            foreach (app(VariantGenerator::class)->generate($code, $options) as $index => $draft) {
                $label = (string) $draft['label'];
                $skus[] = new SkuData(
                    code: $code.'-'.($index + 1),
                    baseUnitId: $piece->id,
                    units: [new SkuUnitData(null, $piece->id, 1, true, new SkuPricingData($prices[$label] ?? '10.00'))],
                    optionValues: $draft['option_values'],
                    openingStock: $stock[$label] ?? 50,
                    path: "skus.{$index}",
                );
            }

            return app(CreateProduct::class)->handle(new ProductData(name: $name, categoryId: $category->id, skus: $skus !== [] ? $skus : throw new LogicException('A colour makes a variant.'), options: $options))->refresh();
        });
    }

    /** D91: the id of a SKU's default unit (what a line naming only the SKU is in). */
    protected function defaultUnitId(Sku|int $sku): int
    {
        $skuId = $sku instanceof Sku ? $sku->id : $sku;

        return app(TenantContext::class)->has()
            ? SkuUnit::query()->where('sku_id', $skuId)->where('is_default', true)->sole()->id
            : SkuUnit::query()->withoutGlobalScopes()->where('sku_id', $skuId)->where('is_default', true)->sole()->id; // a test may ask outside any tenant context
    }

    /**
     * D91: a further unit of a SKU (a carton of 12 at 1000.00 by default), optionally with tiers `[min => price]`.
     *
     * @param  array<string, mixed>  $attributes
     * @param  array<int, string>  $tiers
     */
    protected function addUnit(Sku $sku, array $attributes = [], array $tiers = []): SkuUnit
    {
        return $this->asTenant(Tenant::query()->findOrFail($sku->tenant_id), function () use ($sku, $attributes, $tiers): SkuUnit {
            $unit = SkuUnit::factory()->create(['sku_id' => $sku->id, 'conversion_factor' => 12, 'base_price' => '1000.00', ...$attributes]);

            foreach ($tiers as $min => $price) {
                SkuPriceTier::factory()->create(['sku_unit_id' => $unit->id, 'min_quantity' => $min, 'unit_price' => $price]);
            }

            return $unit->refresh();
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

    /**
     * A SKU of a Coming Soon product that takes pre-orders (BR-O14, D88), shaped like orderableSku() (cartons of 20, MOQ 5,
     * 90.00, 10+ → 85.00) with no stock, optionally capped at $limit selling units over every open pre-order.
     *
     * @param  array<string, mixed>  $attributes  SKU overrides
     */
    protected function preorderableSku(array $attributes = [], ?int $limit = null, ?Tenant $tenant = null, string $name = 'سلاح التلميذ ٢٠٢٧'): Sku
    {
        $sku = $this->orderableSku($attributes, 0, ProductStatus::ComingSoon, $tenant, $name);

        $this->asTenant($tenant ?? $this->tenant, fn (): int => Product::query()->whereKey($sku->product_id)->update(['preorder_enabled' => true, 'preorder_limit' => $limit, 'expected_at' => '2026-12-01']));

        return $sku->refresh();
    }

    /**
     * Puts pre-order lines in a customer's cart, as `[sku_id => quantity]`.
     *
     * @param  array<int, int>  $lines
     */
    protected function fillPreorderCart(Customer $customer, array $lines): void
    {
        $this->asTenant(Tenant::query()->findOrFail($customer->tenant_id), function () use ($customer, $lines): void {
            $cart = Cart::query()->firstOrCreate(['customer_id' => $customer->id]);

            foreach ($lines as $skuId => $quantity) {
                CartItem::query()->updateOrCreate(['cart_id' => $cart->id, 'sku_unit_id' => $this->defaultUnitId($skuId), 'kind' => CartLineKind::Preorder], ['sku_id' => $skuId, 'quantity' => $quantity]);
            }
        });
    }

    /**
     * A real pre-order placed through PlacePreorder (BR-O14): the cart's pre-order lines are filled with
     * `[sku_id => quantity]` and confirmed to the customer's first address, created when there is none.
     *
     * @param  array<int, int>  $lines
     */
    protected function placePreorder(Customer $customer, array $lines, ?string $note = null): Order
    {
        $this->fillPreorderCart($customer, $lines);
        $tenant = Tenant::query()->findOrFail($customer->tenant_id);
        $address = $this->asTenant($tenant, fn (): ?CustomerAddress => CustomerAddress::query()->where('customer_id', $customer->id)->first())
            ?? $this->addressOf($customer);

        return $this->asTenant($tenant, fn (): Order => app(PlacePreorder::class)->handle($customer, new CheckoutData($address->id, $note))->refresh());
    }
}
