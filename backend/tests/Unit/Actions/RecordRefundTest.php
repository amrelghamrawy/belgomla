<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Collections\Actions\RecordRefund;
use App\Modules\Collections\Enums\CollectionKind;
use App\Modules\Collections\Enums\CollectionMethod;
use App\Modules\Collections\Models\CashCollection;
use App\Modules\Collections\Services\ReturnRefunds;
use App\Modules\Orders\Enums\CollectionStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderReturn;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Database\QueryException;

/*
| A delivered order of 500.00, collected in full, after a return of 200.00 (returned_total already raised, as
| RecordReturn leaves it before refunding): 200.00 was collected beyond the net total of 300.00.
*/

beforeEach(function (): void {
    $this->owner = $this->makeOwner();
    app(TenantContext::class)->set($this->tenant);
    $this->order = Order::factory()->delivered()->create(['items_total' => '500.00', 'order_total' => '500.00', 'returned_total' => '200.00', 'collected_amount' => '500.00', 'collection_status' => 'collected']);
    CashCollection::factory()->for($this->order)->create(['amount' => '500.00']);
    $this->return = OrderReturn::factory()->for($this->order)->create(['total' => '200.00']);

    $this->refund = fn (string $amount = '200.00', ?int $actor = null): CashCollection => app(RecordRefund::class)->handle($this->order, $this->return->id, $amount, $actor ?? $this->owner->id, 'رد نقدي');
});

test('BR-R04 a refund is a cash collection of kind refund tied to its return', function (): void {
    $this->freezeSecond();

    $refund = ($this->refund)();

    expect($refund->refresh()->only(['order_id', 'kind', 'order_return_id', 'amount', 'method', 'recorded_by', 'note']))->toBe([
        'order_id' => $this->order->id, 'kind' => CollectionKind::Refund, 'order_return_id' => $this->return->id, 'amount' => '200.00',
        'method' => CollectionMethod::Cash, 'recorded_by' => $this->owner->id, 'note' => 'رد نقدي',
    ])
        ->and($refund->collected_at->equalTo(now()))->toBeTrue();
});

test('BR-R04 BR-C03 the refund lowers what was collected and the status follows the net total', function (): void {
    ($this->refund)();

    expect($this->order->refresh()->only(['collected_amount', 'collection_status']))->toBe(['collected_amount' => '300.00', 'collection_status' => CollectionStatus::Collected]);
});

test('BR-L a refund is audited as collection.refunded', function (): void {
    $refund = ($this->refund)();

    expect(AuditLog::query()->sole()->only(['action', 'entity_type', 'entity_id', 'new_values']))->toEqual([
        'action' => 'collection.refunded', 'entity_type' => 'collections', 'entity_id' => $refund->id,
        'new_values' => ['order_id' => $this->order->id, 'order_number' => $this->order->number, 'order_return_id' => $this->return->id, 'amount' => '200.00', 'method' => 'cash'],
    ]);
});

test('the refund carries its order', function (): void {
    expect(($this->refund)()->order->collected_amount)->toBe('300.00');
});

test('a failing refund leaves the order and the audit trail untouched', function (): void {
    expect(fn (): CashCollection => ($this->refund)(actor: 999_999))->toThrow(QueryException::class);

    expect($this->order->refresh()->collected_amount)->toBe('500.00')
        ->and(CashCollection::query()->count())->toBe(1)
        ->and(AuditLog::query()->count())->toBe(0);
});

test('BR-R04 the return refunds contract records the refund through RecordRefund', function (): void {
    app(ReturnRefunds::class)->refund($this->order, $this->return->id, '200.00', $this->owner->id);

    expect(CashCollection::query()->where('kind', CollectionKind::Refund)->sole()->amount)->toBe('200.00');
});

test('BR-R06 a return has a live refund until the refund is voided', function (): void {
    expect(app(ReturnRefunds::class)->hasLiveRefund($this->return->id))->toBeFalse();

    $refund = ($this->refund)();
    expect(app(ReturnRefunds::class)->hasLiveRefund($this->return->id))->toBeTrue();

    $refund->forceFill(['voided_at' => now(), 'void_reason' => 'خطأ'])->save();
    expect(app(ReturnRefunds::class)->hasLiveRefund($this->return->id))->toBeFalse();
});
