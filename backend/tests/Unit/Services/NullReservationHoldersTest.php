<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Sku;
use App\Modules\Inventory\Contracts\ReservationHolders;
use App\Modules\Inventory\Services\NullReservationHolders;

test('until Orders binds its own, the container gives the null reservation holders', function (): void {
    expect(app(ReservationHolders::class))->toBeInstanceOf(NullReservationHolders::class);
});

test('the null reservation holders name no orders', function (): void {
    expect(new NullReservationHolders()->ordersHolding(new Sku))->toBe([]);
});
