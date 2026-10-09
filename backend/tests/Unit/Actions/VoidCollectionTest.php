<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Collections\Actions\RecordCollection;
use App\Modules\Collections\Actions\VoidCollection;
use App\Modules\Collections\Data\CollectionData;
use App\Modules\Collections\Exceptions\AlreadyVoided;
use App\Modules\Collections\Models\CashCollection;
use App\Modules\Orders\Enums\CollectionStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Database\QueryException;

beforeEach(function (): void {
    $this->owner = $this->makeOwner();
    app(TenantContext::class)->set($this->tenant);
    $this->order = Order::factory()->delivered()->create(['items_total' => '500.00', 'order_total' => '500.00']);
    $this->first = app(RecordCollection::class)->handle($this->order, new CollectionData('200.00'), $this->owner->id);
    $this->second = app(RecordCollection::class)->handle($this->order, new CollectionData('300.00'), $this->owner->id);
    AuditLog::query()->delete();

    $this->void = fn (CashCollection $collection, ?int $actor = null): CashCollection => app(VoidCollection::class)->handle($collection, 'تسجيل مكرر', $actor ?? $this->owner->id);
});

test('BR-C02 a voided collection keeps its row with when, why and by whom', function (): void {
    $this->freezeSecond();

    ($this->void)($this->first);

    expect($this->first->refresh()->only(['void_reason', 'voided_by', 'amount']))->toBe(['void_reason' => 'تسجيل مكرر', 'voided_by' => $this->owner->id, 'amount' => '200.00'])
        ->and($this->first->voided_at?->equalTo(now()))->toBeTrue()
        ->and(CashCollection::query()->count())->toBe(2);
});

test('BR-C03 voiding recomputes the order balance and status', function (): void {
    ($this->void)($this->second);

    expect($this->order->refresh()->only(['collected_amount', 'collection_status']))->toBe(['collected_amount' => '200.00', 'collection_status' => CollectionStatus::PartiallyCollected]);
});

test('BR-C03 voiding every collection makes the order unpaid again', function (): void {
    ($this->void)($this->first);
    ($this->void)($this->second);

    expect($this->order->refresh()->only(['collected_amount', 'collection_status']))->toBe(['collected_amount' => '0.00', 'collection_status' => CollectionStatus::Unpaid]);
});

test('BR-C02 a collection is voided only once', function (): void {
    ($this->void)($this->first);

    expect(fn () => ($this->void)($this->first))->toThrow(function (AlreadyVoided $e): void {
        expect([$e->status, $e->errorCode, $e->details])->toBe([409, 'already_voided', ['voided_at' => $this->first->refresh()->voided_at?->toIso8601String()]]);
    });

    expect(AuditLog::query()->count())->toBe(1);
});

test('BR-L a void is audited as collection.voided', function (): void {
    $this->freezeSecond();

    ($this->void)($this->first);

    expect(AuditLog::query()->sole()->only(['action', 'entity_type', 'entity_id', 'old_values', 'new_values']))->toEqual([
        'action' => 'collection.voided',
        'entity_type' => 'collections',
        'entity_id' => $this->first->id,
        'old_values' => ['voided_at' => null],
        'new_values' => ['voided_at' => now()->toIso8601String(), 'void_reason' => 'تسجيل مكرر', 'order_number' => $this->order->number, 'amount' => '200.00'],
    ]);
});

test('the voided collection carries the order with its new balance', function (): void {
    $collection = ($this->void)($this->first);

    expect($collection->order->collected_amount)->toBe('300.00');
});

test('a failing void leaves the collection, the order and the audit trail untouched', function (): void {
    expect(fn () => ($this->void)($this->first, 999_999))->toThrow(QueryException::class);

    expect($this->first->refresh()->voided_at)->toBeNull()
        ->and($this->order->refresh()->collected_amount)->toBe('500.00')
        ->and(AuditLog::query()->count())->toBe(0);
});
