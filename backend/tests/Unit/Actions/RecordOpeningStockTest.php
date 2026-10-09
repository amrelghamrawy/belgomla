<?php

declare(strict_types=1);

use App\Modules\Catalog\Models\Sku;
use App\Modules\Inventory\Actions\RecordOpeningStock;
use App\Modules\Inventory\Enums\MovementType;
use App\Modules\Inventory\Models\InventoryMovement;
use App\Modules\Inventory\Models\SkuInventory;
use App\Modules\Tenancy\TenantContext;

beforeEach(function (): void {
    app(TenantContext::class)->set($this->tenant);
});

test('BR-INV06 records the opening stock as an opening movement by the given user', function (): void {
    $user = $this->makeOwner();
    $sku = Sku::factory()->create();

    app(RecordOpeningStock::class)->handle($sku, 40, $user->id);

    expect(SkuInventory::query()->findOrFail($sku->id)->on_hand)->toBe(40)
        ->and(InventoryMovement::query()->sole()->only(['type', 'quantity', 'actor_id']))->toBe(['type' => MovementType::Opening, 'quantity' => 40, 'actor_id' => $user->id]);
});

test('zero opening stock only creates the stock row', function (): void {
    $sku = Sku::factory()->create();

    app(RecordOpeningStock::class)->handle($sku, 0);

    expect(SkuInventory::query()->findOrFail($sku->id)->on_hand)->toBe(0)
        ->and(InventoryMovement::query()->count())->toBe(0);
});
