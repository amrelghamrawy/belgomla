<?php

declare(strict_types=1);

use App\Modules\Collections\Actions\RecordCollection;
use App\Modules\Collections\Data\CollectionData;
use App\Modules\Collections\Models\CashCollection;
use App\Modules\Collections\Services\RemainingBalanceCollector;
use App\Modules\Orders\Enums\CollectionStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Tenancy\TenantContext;

beforeEach(function (): void {
    $this->owner = $this->makeOwner();
    app(TenantContext::class)->set($this->tenant);
    $this->order = Order::factory()->delivered()->create(['items_total' => '500.00', 'order_total' => '500.00']);
});

test('BR-C04 the full-amount shortcut records one collection for the whole order', function (): void {
    app(RemainingBalanceCollector::class)->collectRemaining($this->order, $this->owner->id);

    expect(CashCollection::query()->sole()->only(['amount', 'recorded_by', 'note']))->toBe(['amount' => '500.00', 'recorded_by' => $this->owner->id, 'note' => null])
        ->and($this->order->collection_status)->toBe(CollectionStatus::Collected);
});

test('BR-C04 the full-amount shortcut collects only what a deposit left', function (): void {
    app(RecordCollection::class)->handle($this->order, new CollectionData('150.00'), $this->owner->id);

    app(RemainingBalanceCollector::class)->collectRemaining($this->order, $this->owner->id);

    expect(CashCollection::query()->orderBy('id')->pluck('amount')->all())->toBe(['150.00', '350.00'])
        ->and($this->order->collected_amount)->toBe('500.00');
});

test('BR-C04 the full-amount shortcut records nothing on an order already collected', function (): void {
    app(RecordCollection::class)->handle($this->order, new CollectionData('500.00'), $this->owner->id);

    app(RemainingBalanceCollector::class)->collectRemaining($this->order, null);

    expect(CashCollection::query()->count())->toBe(1);
});
