<?php

declare(strict_types=1);

use App\Modules\Audit\Enums\ActorType;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Collections\Actions\RecordCollection;
use App\Modules\Collections\Data\CollectionData;
use App\Modules\Collections\Enums\CollectionMethod;
use App\Modules\Collections\Exceptions\CannotCollect;
use App\Modules\Collections\Exceptions\ExceedsBalance;
use App\Modules\Collections\Models\CashCollection;
use App\Modules\Orders\Enums\CollectionStatus;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;

beforeEach(function (): void {
    $this->owner = $this->makeOwner();
    app(TenantContext::class)->set($this->tenant);
    $this->order = Order::factory()->delivered()->create(['items_total' => '500.00', 'order_total' => '500.00']);

    $this->record = fn (string $amount, ?Order $order = null, ?CarbonImmutable $at = null, ?string $note = null, ?int $actor = null): CashCollection => app(RecordCollection::class)
        ->handle($order ?? $this->order, new CollectionData($amount, $at, $note), $actor ?? $this->owner->id);
});

test('BR-C02 a collection is recorded as cash with its amount, time, recorder and note', function (): void {
    $this->freezeSecond();

    $collection = ($this->record)('200.00', note: 'دفعة أولى');

    expect($collection->refresh()->only(['order_id', 'amount', 'method', 'recorded_by', 'note', 'voided_at']))->toBe([
        'order_id' => $this->order->id, 'amount' => '200.00', 'method' => CollectionMethod::Cash, 'recorded_by' => $this->owner->id, 'note' => 'دفعة أولى', 'voided_at' => null,
    ])->and($collection->collected_at->equalTo(now()))->toBeTrue();
});

test('BR-C02 the collection time can be given for cash collected earlier', function (): void {
    $at = CarbonImmutable::parse('2026-10-01 09:30:00', 'UTC');

    $collection = ($this->record)('200.00', at: $at);

    expect($collection->refresh()->collected_at->equalTo($at))->toBeTrue();
});

test('BR-C03 a partial collection leaves the order partially collected', function (): void {
    ($this->record)('200.00');

    expect($this->order->refresh()->only(['collected_amount', 'collection_status']))->toBe(['collected_amount' => '200.00', 'collection_status' => CollectionStatus::PartiallyCollected]);
});

test('BR-C03 collections add up until the order is collected', function (): void {
    ($this->record)('200.00');
    ($this->record)('300.00');

    expect($this->order->refresh()->only(['collected_amount', 'collection_status']))->toBe(['collected_amount' => '500.00', 'collection_status' => CollectionStatus::Collected]);
});

test('BR-C03 voided collections do not count towards the balance', function (): void {
    CashCollection::factory()->for($this->order)->voided()->create(['amount' => '500.00']);

    ($this->record)('500.00');

    expect($this->order->refresh()->collection_status)->toBe(CollectionStatus::Collected);
});

test('BR-C03 collecting more than the remaining balance is rejected with what is left', function (): void {
    ($this->record)('200.00');

    expect(fn () => ($this->record)('300.01'))->toThrow(function (ExceedsBalance $e): void {
        expect([$e->status, $e->errorCode, $e->details])->toBe([422, 'exceeds_balance', ['remaining' => '300.00']]);
    });

    expect(CashCollection::query()->count())->toBe(1)
        ->and($this->order->refresh()->collected_amount)->toBe('200.00');
});

test('BR-C03 nothing more can be collected on a collected order', function (): void {
    ($this->record)('500.00');

    ($this->record)('0.01');
})->throws(ExceedsBalance::class);

test('BR-C04 a collection can be recorded at any status but cancelled', function (OrderStatus $status): void {
    $this->order->forceFill(['status' => $status])->save();

    ($this->record)('100.00');

    expect($this->order->refresh()->collected_amount)->toBe('100.00');
})->with([
    'pending (a deposit)' => [OrderStatus::Pending],
    'confirmed' => [OrderStatus::Confirmed],
    'preparing' => [OrderStatus::Preparing],
    'out for delivery' => [OrderStatus::OutForDelivery],
    'delivered' => [OrderStatus::Delivered],
]);

test('BR-C04 nothing is collected on a cancelled order', function (): void {
    $order = Order::factory()->cancelled()->create(['items_total' => '500.00', 'order_total' => '500.00']);

    expect(fn () => ($this->record)('100.00', $order))->toThrow(function (CannotCollect $e): void {
        expect([$e->status, $e->errorCode])->toBe([409, 'order_cancelled']);
    });

    expect(CashCollection::query()->count())->toBe(0);
});

test('BR-L a collection is audited as collection.recorded', function (): void {
    $this->freezeSecond();

    $collection = ($this->record)('200.00', note: 'دفعة أولى');

    expect(AuditLog::query()->sole()->only(['actor_type', 'action', 'entity_type', 'entity_id', 'old_values', 'new_values']))->toEqual([
        'actor_type' => ActorType::System,
        'action' => 'collection.recorded',
        'entity_type' => 'collections',
        'entity_id' => $collection->id,
        'old_values' => null,
        'new_values' => [
            'order_id' => $this->order->id,
            'order_number' => $this->order->number,
            'amount' => '200.00',
            'method' => 'cash',
            'collected_at' => now()->toIso8601String(),
            'note' => 'دفعة أولى',
        ],
    ]);
});

test('the recorded collection carries the order with its new balance', function (): void {
    $collection = ($this->record)('200.00');

    expect($collection->order->only(['id', 'collected_amount']))->toBe(['id' => $this->order->id, 'collected_amount' => '200.00']);
});

test('a failing insert leaves the order and the audit trail untouched', function (): void {
    expect(fn () => ($this->record)('200.00', actor: 999_999))->toThrow(QueryException::class);

    expect($this->order->refresh()->collected_amount)->toBe('0.00')
        ->and(CashCollection::query()->count())->toBe(0)
        ->and(AuditLog::query()->count())->toBe(0);
});
