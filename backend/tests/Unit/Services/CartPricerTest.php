<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\Sku;
use App\Modules\Customers\Models\Cart;
use App\Modules\Customers\Models\CartItem;
use App\Modules\Customers\Queries\CartQuery;
use App\Modules\Customers\Services\CartPricer;
use App\Modules\Pricing\Data\PricingContext;
use App\Modules\Pricing\Enums\PriceSource;
use App\Modules\Pricing\Models\SkuPriceTier;
use App\Modules\Tenancy\Models\TenantSetting;
use App\Modules\Tenancy\TenantContext;

beforeEach(function (): void {
    app(TenantContext::class)->set($this->tenant);
    $this->customer = $this->makeCustomer();
    $this->cart = Cart::factory()->create(['customer_id' => $this->customer->id]);
});

function pricedSku(string $basePrice = '100.00', array $tiers = [], int $onHand = 1000): Sku
{
    $sku = Product::factory()->withSku(['base_price' => $basePrice, 'moq' => 1], onHand: $onHand)->create(['name' => 'منتج'])->skus()->sole();

    foreach ($tiers as $min => $price) {
        SkuPriceTier::factory()->create(['sku_id' => $sku->id, 'min_quantity' => $min, 'unit_price' => $price]);
    }

    return $sku;
}

function priceCart(App\Modules\Customers\Models\Customer $customer, array $removed = []): App\Modules\Customers\Data\PricedCart
{
    return app(CartPricer::class)->price(app(CartQuery::class)->linesOf($customer), $removed);
}

test('BR-PR04 each line takes the tier with the highest minimum not above its quantity', function (int $quantity, string $unitPrice, ?int $tier): void {
    $sku = pricedSku('100.00', [10 => '90.00', 100 => '80.00']);
    CartItem::factory()->create(['cart_id' => $this->cart->id, 'sku_id' => $sku->id, 'quantity' => $quantity]);

    $line = priceCart($this->customer)->lines[0];

    expect($line->price->unitPrice)->toBe($unitPrice)
        ->and($line->price->tierMinQuantity)->toBe($tier)
        ->and($line->price->lineTotal)->toBe(bcmul($unitPrice, (string) $quantity, 2));
})->with([
    'below the first tier' => [9, '100.00', null],
    'exactly the first tier' => [10, '90.00', 10],
    'between tiers' => [99, '90.00', 10],
    'exactly the second tier' => [100, '80.00', 100],
]);

test('BR-PR02 tiers are per sku: quantities of two skus never combine', function (): void {
    $a = pricedSku('100.00', [10 => '90.00']);
    $b = pricedSku('100.00', [10 => '90.00']);
    CartItem::factory()->create(['cart_id' => $this->cart->id, 'sku_id' => $a->id, 'quantity' => 5]);
    CartItem::factory()->create(['cart_id' => $this->cart->id, 'sku_id' => $b->id, 'quantity' => 5]);

    $cart = priceCart($this->customer);

    expect($cart->lines[0]->price->unitPrice)->toBe('100.00')
        ->and($cart->lines[1]->price->unitPrice)->toBe('100.00')
        ->and($cart->itemsTotal)->toBe('1000.00');
});

test('BR-MOV02 the mov is checked against the items total', function (bool $enabled, ?string $amount, bool $movEnabled, ?string $movAmount, bool $satisfied): void {
    TenantSetting::query()->whereKey($this->tenant->id)->update(['mov_enabled' => $enabled, 'mov_amount' => $amount]);
    CartItem::factory()->create(['cart_id' => $this->cart->id, 'sku_id' => pricedSku('100.00')->id, 'quantity' => 7]);

    $cart = priceCart($this->customer);

    expect([$cart->itemsTotal, $cart->movEnabled, $cart->movAmount, $cart->movSatisfied])->toBe(['700.00', $movEnabled, $movAmount, $satisfied]);
})->with([
    'disabled' => [false, '1000.00', false, null, true],
    'enabled, total below' => [true, '1000.00', true, '1000.00', false],
    'enabled, total exactly the amount' => [true, '700.00', true, '700.00', true],
    'enabled, total one piaster below' => [true, '700.01', true, '700.01', false],
    'enabled without an amount counts as disabled' => [true, null, false, null, true],
]);

test('an empty cart totals zero and satisfies no mov', function (): void {
    TenantSetting::query()->whereKey($this->tenant->id)->update(['mov_enabled' => true, 'mov_amount' => '1.00']);

    $cart = priceCart($this->customer);

    expect([$cart->lines, $cart->itemsTotal, $cart->movSatisfied])->toBe([[], '0.00', false]);
});

test('a line carries the product name, its unit, moq and stock in base units', function (): void {
    $sku = pricedSku('50.00', onHand: 37);
    CartItem::factory()->create(['cart_id' => $this->cart->id, 'sku_id' => $sku->id, 'quantity' => 2]);

    $line = priceCart($this->customer)->lines[0];

    expect([$line->skuId, $line->skuUnitId, $line->productName, $line->unit, $line->conversionFactor, $line->quantity, $line->moq, $line->available])
        ->toBe([$sku->id, $this->defaultUnitId($sku), 'منتج', App\Modules\Catalog\Models\SkuUnit::query()->with('unit')->findOrFail($this->defaultUnitId($sku))->unit->name, 1, 2, 1, 37]);
});

test('D91 BR-PR02 each unit of a sku is priced on its own tiers, its MOQ and factor on its line', function (): void {
    $sku = pricedSku('10.00', [10 => '9.00']);
    $box = $this->addUnit($sku, ['conversion_factor' => 12, 'base_price' => '100.00', 'moq' => 2], [5 => '95.00']);
    CartItem::factory()->create(['cart_id' => $this->cart->id, 'sku_id' => $sku->id, 'quantity' => 10]);
    CartItem::factory()->create(['cart_id' => $this->cart->id, 'sku_id' => $sku->id, 'sku_unit_id' => $box->id, 'quantity' => 5]);

    $cart = priceCart($this->customer);

    expect(array_map(fn (App\Modules\Customers\Data\PricedCartLine $line): array => [$line->skuUnitId, $line->conversionFactor, $line->moq, $line->price->unitPrice, $line->price->lineTotal], $cart->lines))->toBe([
        [$this->defaultUnitId($sku), 1, 1, '9.00', '90.00'],
        [$box->id, 12, 2, '95.00', '475.00'],
    ])->and($cart->itemsTotal)->toBe('565.00');
});

test('a sku without a stock row shows nothing available', function (): void {
    $sku = Product::factory()->withSku()->create()->skus()->sole();
    CartItem::factory()->create(['cart_id' => $this->cart->id, 'sku_id' => $sku->id, 'quantity' => 1]);

    expect(priceCart($this->customer)->lines[0]->available)->toBe(0);
});

test('BR-P04 the removed lines are passed through for the message', function (): void {
    expect(priceCart($this->customer, [['product_name' => 'قديم', 'reason' => 'inactive']])->removed)->toBe([['product_name' => 'قديم', 'reason' => 'inactive']]);
});

test('BR-PR14 the cart prices each line at the customer\'s context, with its source', function (): void {
    $negotiated = pricedSku('100.00', [10 => '90.00']);
    $grouped = pricedSku('100.00', [10 => '90.00']);
    CartItem::factory()->create(['cart_id' => $this->cart->id, 'sku_id' => $negotiated->id, 'quantity' => 10]);
    CartItem::factory()->create(['cart_id' => $this->cart->id, 'sku_id' => $grouped->id, 'quantity' => 1]);

    $cart = app(CartPricer::class)->price(app(CartQuery::class)->linesOf($this->customer), pricing: new PricingContext('15.00', [$this->defaultUnitId($negotiated) => '95.00']));

    expect(array_map(fn (App\Modules\Customers\Data\PricedCartLine $line): array => [$line->price->unitPrice, $line->price->source], $cart->lines))->toBe([
        ['95.00', PriceSource::Customer],
        ['85.00', PriceSource::Group],
    ])
        ->and($cart->itemsTotal)->toBe('1035.00');
});

test('BR-PR15 the cart prices a line at a promotion in the context, with the promotion', function (): void {
    $sku = pricedSku('100.00', [10 => '90.00']);
    CartItem::factory()->create(['cart_id' => $this->cart->id, 'sku_id' => $sku->id, 'quantity' => 2]);
    $promotion = new App\Modules\Pricing\Data\PromotionPrice(4, 'عرض', '25.00', Carbon\CarbonImmutable::parse('2026-12-01'));

    $line = app(CartPricer::class)->price(app(CartQuery::class)->linesOf($this->customer), pricing: new PricingContext(promotions: [$sku->id => $promotion]))->lines[0];

    expect([$line->price->unitPrice, $line->price->source, $line->price->promotion])->toBe(['75.00', PriceSource::Promotion, $promotion]);
});
