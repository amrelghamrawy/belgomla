<?php

declare(strict_types=1);

use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\ReturnPricing;

test('D74 without an order discount an amount returns as it is', function (): void {
    expect(app(ReturnPricing::class)->prorated(new Order(['items_total' => '1390.00', 'discount_amount' => '0.00', 'order_total' => '1390.00']), '85.00'))->toBe('85.00');
});

test('D85 an amount returns less the order discount share, rounded half-up to the piaster', function (string $amount, string $returned): void {
    $order = new Order(['items_total' => '1336.00', 'discount_amount' => '50.00', 'order_total' => '1286.00']);

    expect(app(ReturnPricing::class)->prorated($order, $amount))->toBe($returned);
})->with([
    'one unit at 81.00 (77.9686)' => ['81.00', '77.97'],
    'a whole line at 486.00 (467.8114)' => ['486.00', '467.81'],
]);

test('D27 exactly half a piaster rounds up', function (): void {
    $order = new Order(['items_total' => '200.00', 'discount_amount' => '100.00', 'order_total' => '100.00']);

    expect(app(ReturnPricing::class)->prorated($order, '0.01'))->toBe('0.01');
});
