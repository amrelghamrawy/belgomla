<?php

declare(strict_types=1);

use App\Modules\Catalog\Enums\ProductStatus;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\Sku;
use App\Modules\Customers\Enums\CustomerStatus;
use App\Modules\Customers\Models\Cart;
use App\Modules\Customers\Models\CartItem;
use App\Modules\Pricing\Models\SkuPriceTier;
use App\Modules\Tenancy\Models\TenantSetting;

beforeEach(function (): void {
    $this->customer = $this->actingAsCustomer();
    $this->sku = cartSku($this->tenant);
});

/** An active product whose SKU sells in cartons of 20, MOQ 5, base price 90, tier 10+ → 85, with 400 pieces (20 cartons) in stock. */
function cartSku(App\Modules\Tenancy\Models\Tenant $tenant, ProductStatus $status = ProductStatus::Active, int $onHand = 400): Sku
{
    return app(App\Modules\Tenancy\TenantContext::class)->runAs($tenant, function () use ($status, $onHand): Sku {
        $carton = App\Modules\Catalog\Models\Unit::query()->firstOrCreate(['name' => 'كرتونة']);
        $sku = Product::factory()
            ->state(['name' => 'الأضواء علوم', 'status' => $status])
            ->withSku(['selling_unit_id' => $carton->id, 'conversion_factor' => 20, 'moq' => 5, 'base_price' => '90.00'], onHand: $onHand)
            ->create()
            ->skus()
            ->sole();
        SkuPriceTier::factory()->create(['sku_id' => $sku->id, 'min_quantity' => 10, 'unit_price' => '85.00']);

        return $sku;
    });
}

function addToCart(int $skuId, int $quantity): Illuminate\Testing\TestResponse
{
    return test()->tenantJson('POST', '/api/store/cart/items', ['sku_id' => $skuId, 'quantity' => $quantity]);
}

test('BR-CU06 an empty cart has no items and a zero total', function (): void {
    $this->tenantJson('GET', '/api/store/cart')
        ->assertOk()
        ->assertExactJson(['data' => [
            'items' => [],
            'removed_items' => [],
            'items_total' => '0.00',
            'mov' => ['enabled' => false, 'amount' => null, 'satisfied' => true],
            'preorders' => ['items' => [], 'items_total' => '0.00'],
        ]]);
});

test('BR-CU06 BR-PR04 adding a sku returns the cart with the price resolved for the quantity', function (): void {
    addToCart($this->sku->id, 6)
        ->assertOk()
        ->assertExactJson(['data' => [
            'items' => [[
                'sku_id' => $this->sku->id,
                'sku_unit_id' => $this->defaultUnitId($this->sku),
                'product_name' => 'الأضواء علوم',
                'variant' => null,
                'is_bundle' => false,
                'product_slug' => $this->sku->product->slug,
                'unit' => 'كرتونة',
                'conversion_factor' => 20,
                'quantity' => 6,
                'unit_price' => '90.00',
                'tier_min_quantity' => null,
                'price_source' => 'base',
                'promotion' => null,
                'line_total' => '540.00',
                'moq' => 5,
                'available' => 400,
            ]],
            'removed_items' => [],
            'items_total' => '540.00',
            'mov' => ['enabled' => false, 'amount' => null, 'satisfied' => true],
            'preorders' => ['items' => [], 'items_total' => '0.00'],
        ]]);

    $this->assertDatabaseHas('cart_items', ['sku_unit_id' => $this->defaultUnitId($this->sku), 'quantity' => 6]);
});

test('BR-PR04 the tier price applies from its minimum quantity, the base price below it', function (int $quantity, string $unitPrice, ?int $tier, string $lineTotal): void {
    addToCart($this->sku->id, $quantity)
        ->assertOk()
        ->assertJsonPath('data.items.0.unit_price', $unitPrice)
        ->assertJsonPath('data.items.0.tier_min_quantity', $tier)
        ->assertJsonPath('data.items.0.line_total', $lineTotal);
})->with([
    'tier min − 1' => [9, '90.00', null, '810.00'],
    'tier min' => [10, '85.00', 10, '850.00'],
]);

test('BR-CU06 adding the same sku again replaces its quantity', function (): void {
    addToCart($this->sku->id, 5)->assertOk();

    addToCart($this->sku->id, 6)->assertOk()->assertJsonCount(1, 'data.items')->assertJsonPath('data.items.0.quantity', 6);

    expect($this->inTenant(fn () => CartItem::query()->count()))->toBe(1)
        ->and($this->inTenant(fn () => Cart::query()->count()))->toBe(1);
});

test('BR-MOQ01 a quantity below the moq is refused', function (): void {
    addToCart($this->sku->id, 4)
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'cart_line_invalid')
        ->assertJsonPath('error.items.0', ['sku_id' => $this->sku->id, 'sku_unit_id' => $this->defaultUnitId($this->sku), 'reason' => 'below_moq', 'moq' => 5]);

    $this->assertDatabaseCount('cart_items', 0);
});

test('BR-INV01 more than the stock is refused, with what is left in selling units', function (): void {
    addToCart($this->sku->id, 21)
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'cart_line_invalid')
        ->assertJsonPath('error.items.0', ['sku_id' => $this->sku->id, 'sku_unit_id' => $this->defaultUnitId($this->sku), 'reason' => 'insufficient_stock', 'available' => 20]);
});

test('BR-INV01 exactly the stock is accepted', function (): void {
    addToCart($this->sku->id, 20)->assertOk();
});

test('BR-P04 D21 an inactive or coming-soon product cannot be added', function (ProductStatus $status): void {
    $sku = cartSku($this->tenant, $status);

    addToCart($sku->id, 5)
        ->assertUnprocessable()
        ->assertJsonPath('error.items.0.reason', 'product_inactive');
})->with(['inactive' => [ProductStatus::Inactive], 'coming soon' => [ProductStatus::ComingSoon]]);

test('BR-P04 a product that became inactive leaves the cart at the next load, once, with a message', function (): void {
    addToCart($this->sku->id, 5)->assertOk();
    $this->inTenant(fn () => Product::query()->update(['status' => ProductStatus::Inactive]));

    $this->tenantJson('GET', '/api/store/cart')
        ->assertOk()
        ->assertJsonPath('data.items', [])
        ->assertJsonPath('data.removed_items', [['product_name' => 'الأضواء علوم', 'reason' => 'inactive']])
        ->assertJsonPath('data.items_total', '0.00');

    $this->tenantJson('GET', '/api/store/cart')->assertJsonPath('data.removed_items', []);
    $this->assertDatabaseCount('cart_items', 0);
});

test('BR-MOV02 the mov check compares the items total after tier pricing', function (string $amount, bool $satisfied): void {
    TenantSetting::query()->whereKey($this->tenant->id)->update(['mov_enabled' => true, 'mov_amount' => $amount]);

    addToCart($this->sku->id, 6)
        ->assertOk()
        ->assertJsonPath('data.mov', ['enabled' => true, 'amount' => $amount, 'satisfied' => $satisfied]);
})->with([
    'total below the mov' => ['540.01', false],
    'total exactly the mov' => ['540.00', true],
    'total above the mov' => ['539.99', true],
]);

test('BR-CU06 changing a quantity re-checks it and re-prices the cart', function (): void {
    addToCart($this->sku->id, 5)->assertOk();

    $this->tenantJson('PATCH', "/api/store/cart/items/{$this->defaultUnitId($this->sku)}", ['quantity' => 4])
        ->assertUnprocessable()
        ->assertJsonPath('error.items.0.reason', 'below_moq');

    $this->tenantJson('PATCH', "/api/store/cart/items/{$this->defaultUnitId($this->sku)}", ['quantity' => 6])
        ->assertOk()
        ->assertJsonPath('data.items.0.quantity', 6)
        ->assertJsonPath('data.items_total', '540.00');
});

test('changing or removing a sku that is not in the cart is 404', function (string $method): void {
    $this->tenantJson($method, "/api/store/cart/items/{$this->defaultUnitId($this->sku)}", ['quantity' => 5])
        ->assertNotFound()
        ->assertJsonPath('error.code', 'not_found');
})->with(['PATCH', 'DELETE']);

test('BR-CU06 removing a sku returns the cart without it', function (): void {
    addToCart($this->sku->id, 5)->assertOk();

    $this->tenantJson('DELETE', "/api/store/cart/items/{$this->defaultUnitId($this->sku)}")
        ->assertOk()
        ->assertJsonPath('data.items', []);
});

test('BR-CU06 clearing the cart empties it', function (): void {
    addToCart($this->sku->id, 5)->assertOk();

    $this->tenantJson('DELETE', '/api/store/cart')->assertNoContent();

    $this->assertDatabaseCount('cart_items', 0);
});

test('BR-CU06 each customer sees only their own cart', function (): void {
    addToCart($this->sku->id, 5)->assertOk();

    $this->actingAsCustomer();

    $this->tenantJson('GET', '/api/store/cart')->assertJsonPath('data.items', []);
});

test('BR-T04 a sku id of another tenant is 404 and nothing is added', function (): void {
    $foreign = cartSku($this->otherTenant());

    addToCart($foreign->id, 5)
        ->assertNotFound()
        ->assertJsonPath('error.code', 'not_found');

    $this->assertDatabaseCount('cart_items', 0);
    $this->assertDatabaseHas('sku_inventory', ['sku_id' => $foreign->id, 'on_hand' => 400]);
});

test('add-to-cart validation', function (array $body, string $field): void {
    $this->tenantJson('POST', '/api/store/cart/items', $body)
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonStructure(['error' => ['details' => [$field]]]);
})->with([
    'missing sku' => [['quantity' => 5], 'sku_id'],
    'sku not an integer' => [['sku_id' => 'abc', 'quantity' => 5], 'sku_id'],
    'missing quantity' => [['sku_id' => 1], 'quantity'],
    'BR-U05 fractional quantity' => [['sku_id' => 1, 'quantity' => 2.5], 'quantity'],
    'zero quantity' => [['sku_id' => 1, 'quantity' => 0], 'quantity'],
    'quantity too large' => [['sku_id' => 1, 'quantity' => 100_001], 'quantity'],
]);

test('update quantity validation', function (): void {
    $this->tenantJson('PATCH', "/api/store/cart/items/{$this->defaultUnitId($this->sku)}", ['quantity' => 0])
        ->assertUnprocessable()
        ->assertJsonStructure(['error' => ['details' => ['quantity']]]);
});

test('BR-CU05 a customer blocked mid-session loses the cart', function (CustomerStatus $status): void {
    $this->customer->forceFill(['status' => $status])->save();

    $this->tenantJson('GET', '/api/store/cart')
        ->assertForbidden()
        ->assertJsonPath('error.code', $status->value);
    addToCart($this->sku->id, 5)->assertForbidden();
})->with(['blocked' => [CustomerStatus::Blocked], 'pending approval' => [CustomerStatus::PendingApproval]]);

/*
| D91 (V2 Task 13): the SKU is also sold by the piece (factor 1, 5.00, MOQ 10).
*/

test('D91 BR-CU06 a unit is added by sku_unit_id and sits next to the SKU\'s other unit as its own line', function (): void {
    $piece = $this->addUnit($this->sku, ['conversion_factor' => 1, 'base_price' => '5.00', 'moq' => 10]);
    addToCart($this->sku->id, 6)->assertOk();

    $this->tenantJson('POST', '/api/store/cart/items', ['sku_unit_id' => $piece->id, 'quantity' => 12])
        ->assertOk()
        ->assertJsonCount(2, 'data.items')
        ->assertJsonPath('data.items.1.sku_id', $this->sku->id)
        ->assertJsonPath('data.items.1.sku_unit_id', $piece->id)
        ->assertJsonPath('data.items.1.conversion_factor', 1)
        ->assertJsonPath('data.items.1.line_total', '60.00')
        ->assertJsonPath('data.items_total', '600.00');
});

test('D91 BR-CU06 a line is changed and removed by its SKU unit, the other unit left alone', function (): void {
    $piece = $this->addUnit($this->sku, ['conversion_factor' => 1, 'base_price' => '5.00', 'moq' => 10]);
    addToCart($this->sku->id, 6)->assertOk();
    $this->tenantJson('POST', '/api/store/cart/items', ['sku_unit_id' => $piece->id, 'quantity' => 12])->assertOk();

    $this->tenantJson('PATCH', "/api/store/cart/items/{$piece->id}", ['quantity' => 20])->assertOk()->assertJsonPath('data.items.1.quantity', 20)->assertJsonPath('data.items.0.quantity', 6);
    $this->tenantJson('DELETE', "/api/store/cart/items/{$piece->id}")->assertOk()->assertJsonCount(1, 'data.items')->assertJsonPath('data.items.0.sku_unit_id', $this->defaultUnitId($this->sku));
});

test('D91 BR-INV01 the pieces asked next to the cartons in the cart must fit what is left', function (): void {
    $piece = $this->addUnit($this->sku, ['conversion_factor' => 1, 'base_price' => '5.00', 'moq' => 10]);
    addToCart($this->sku->id, 19)->assertOk();

    $this->tenantJson('POST', '/api/store/cart/items', ['sku_unit_id' => $piece->id, 'quantity' => 21])
        ->assertUnprocessable()
        ->assertJsonPath('error.items.0', ['sku_id' => $this->sku->id, 'sku_unit_id' => $piece->id, 'reason' => 'insufficient_stock', 'available' => 20]);
});

test('D91 BR-T04 a SKU unit of another tenant is not found and nothing is written', function (): void {
    $theirs = cartSku($this->otherTenant());

    $this->tenantJson('POST', '/api/store/cart/items', ['sku_unit_id' => $this->defaultUnitId($theirs), 'quantity' => 5])->assertNotFound();

    $this->assertDatabaseCount('cart_items', 0);
});

test('BR-P14 two colours of one pen are two lines, each naming its variant', function (): void {
    $this->variantProduct(colours: ['أزرق', 'أحمر'], prices: ['أحمر' => '12.00']);
    [$blue, $red] = $this->inTenant(fn (): array => Sku::query()->whereIn('code', ['PEN-1', 'PEN-2'])->orderBy('code')->get()->all());

    addToCart($blue->id, 3)->assertOk();
    addToCart($red->id, 2)
        ->assertOk()
        ->assertJsonCount(2, 'data.items')
        ->assertJsonPath('data.items.*.sku_id', [$blue->id, $red->id])
        ->assertJsonPath('data.items.*.product_name', ['قلم جاف', 'قلم جاف'])
        ->assertJsonPath('data.items.*.variant', ['أزرق', 'أحمر'])
        ->assertJsonPath('data.items.*.line_total', ['30.00', '24.00'])
        ->assertJsonPath('data.items_total', '54.00');
});

test('BR-P14 an inactive variant is refused as product_inactive', function (): void {
    $this->variantProduct(colours: ['أزرق', 'أحمر']);
    $red = $this->inTenant(fn (): Sku => Sku::query()->where('code', 'PEN-2')->sole());
    $this->inTenant(fn () => $red->update(['status' => 'inactive']));

    addToCart($red->id, 3)
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'cart_line_invalid')
        ->assertJsonPath('error.items.0', ['sku_id' => $red->id, 'sku_unit_id' => $this->defaultUnitId($red), 'reason' => 'product_inactive']);

    $this->assertDatabaseCount('cart_items', 0);
});
