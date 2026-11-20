<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Sku;
use App\Modules\Inventory\Queries\StockHeldQuery;
use App\Modules\Tenancy\TenantContext;

beforeEach(function (): void {
    app(TenantContext::class)->set($this->tenant);
});

test('D99 c a sku holds stock when anything is on hand or reserved', function (int $onHand, int $reserved, bool $holds): void {
    $sku = Sku::factory()->withStock($onHand, $reserved)->create();

    expect(app(StockHeldQuery::class)->holds($sku->id))->toBe($holds);
})->with([
    'on hand' => [3, 0, true],
    'reserved only' => [2, 2, true],
    'nothing' => [0, 0, false],
]);

test('D99 c a sku without a stock row or of another tenant holds nothing', function (): void {
    $foreign = $this->inTenant(fn () => Sku::factory()->withStock(9)->create(), $this->otherTenant());

    expect(app(StockHeldQuery::class)->holds(Sku::factory()->create()->id))->toBeFalse()
        ->and(app(StockHeldQuery::class)->holds($foreign->id))->toBeFalse();
});
