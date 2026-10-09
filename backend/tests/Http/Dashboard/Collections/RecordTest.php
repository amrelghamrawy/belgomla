<?php

declare(strict_types=1);

use App\Modules\Audit\Enums\ActorType;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Collections\Models\CashCollection;
use App\Modules\Orders\Enums\OrderStatus;

beforeEach(function (): void {
    $this->owner = $this->actingAsOwner();
    $this->order = $this->placeOrder($this->makeCustomer(), [$this->orderableSku()->id => 6]);
    $this->record = fn (array $body, ?int $number = null, array $headers = []) => $this->tenantJson('POST', '/api/dashboard/orders/'.($number ?? $this->order->number).'/collections', $body, headers: $headers);
});

test('BR-C02 the merchant records a cash collection and gets it back with the new order balance', function (): void {
    $this->freezeSecond();

    ($this->record)(['amount' => '200', 'note' => 'دفعة أولى'])
        ->assertCreated()
        ->assertJsonPath('data.amount', '200.00')
        ->assertJsonPath('data.method', 'cash')
        ->assertJsonPath('data.recorded_by', $this->owner->id)
        ->assertJsonPath('data.note', 'دفعة أولى')
        ->assertJsonPath('data.collected_at', now()->toIso8601String())
        ->assertJsonPath('data.is_voided', false)
        ->assertJsonPath('data.order', [
            'id' => $this->order->id, 'number' => $this->order->number, 'order_total' => '540.00', 'collected_amount' => '200.00', 'collection_status' => 'partially_collected',
        ])
        ->assertExactJsonStructure(['data' => ['id', 'order' => ['id', 'number', 'order_total', 'collected_amount', 'collection_status'], 'amount', 'method', 'method_label', 'collected_at', 'recorded_by', 'note', 'is_voided', 'voided_at', 'void_reason', 'voided_by', 'created_at']]);
});

test('BR-C04 a deposit can be recorded on a pending order', function (): void {
    ($this->record)(['amount' => '100.00'])->assertCreated();

    $this->assertDatabaseHas('orders', ['id' => $this->order->id, 'status' => 'pending', 'collected_amount' => '100.00', 'collection_status' => 'partially_collected']);
});

test('BR-C03 collecting the remaining balance makes the order collected', function (): void {
    ($this->record)(['amount' => '540.00'])->assertCreated()->assertJsonPath('data.order.collection_status', 'collected');
});

test('BR-C02 a collection may be dated earlier', function (): void {
    ($this->record)(['amount' => '100', 'collected_at' => '2026-10-01T09:30:00Z'])->assertCreated()->assertJsonPath('data.collected_at', '2026-10-01T09:30:00+00:00');
});

test('BR-L recording is audited with the merchant as actor', function (): void {
    ($this->record)(['amount' => '200'])->assertCreated();

    expect(AuditLog::withoutTenantScope()->where('action', 'collection.recorded')->sole()->only(['actor_type', 'actor_id', 'entity_type']))
        ->toEqual(['actor_type' => ActorType::TenantUser, 'actor_id' => $this->owner->id, 'entity_type' => 'collections']);
});

test('BR-C03 more than the remaining balance is 422 exceeds_balance and nothing is recorded', function (): void {
    ($this->record)(['amount' => '500'])->assertCreated();

    ($this->record)(['amount' => '40.01'])
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'exceeds_balance')
        ->assertJsonPath('error.details', ['remaining' => '40.00']);

    expect(CashCollection::withoutTenantScope()->count())->toBe(1);
});

test('BR-C04 a cancelled order takes no collections: 409 order_cancelled', function (): void {
    $this->inTenant(fn () => $this->order->forceFill(['status' => OrderStatus::Cancelled])->save());

    ($this->record)(['amount' => '100'])->assertConflict()->assertJsonPath('error.code', 'order_cancelled');
});

test('every collection field is validated', function (array $body, string $field): void {
    ($this->record)($body)
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonStructure(['error' => ['details' => [$field]]]);
})->with([
    'amount missing' => [[], 'amount'],
    'amount not a number' => [['amount' => 'مئة'], 'amount'],
    'amount zero' => [['amount' => '0'], 'amount'],
    'amount negative' => [['amount' => '-5'], 'amount'],
    'amount with three decimals' => [['amount' => '10.005'], 'amount'],
    'amount too large' => [['amount' => '10000000000'], 'amount'],
    'collected_at not a date' => [['amount' => '10', 'collected_at' => 'أمس'], 'collected_at'],
    'collected_at in the future' => [['amount' => '10', 'collected_at' => '2999-01-01T00:00:00Z'], 'collected_at'],
    'note too long' => [['amount' => '10', 'note' => str_repeat('س', 301)], 'note'],
]);

test('D55 the same Idempotency-Key twice records once', function (): void {
    ($this->record)(['amount' => '100'], headers: ['Idempotency-Key' => 'collect-1'])->assertCreated();

    ($this->record)(['amount' => '100'], headers: ['Idempotency-Key' => 'collect-1'])->assertCreated()->assertHeader('Idempotent-Replayed', 'true');

    expect(CashCollection::withoutTenantScope()->count())->toBe(1);
});

test('BR-T04 an order number of another tenant is 404 and nothing is recorded there', function (): void {
    $other = $this->otherTenant();
    $theirs = $this->placeOrder($this->makeCustomer(tenant: $other), [$this->orderableSku(tenant: $other)->id => 5]);
    $this->inTenant(fn () => $this->order->forceFill(['number' => 99])->save());

    ($this->record)(['amount' => '100'], $theirs->number)->assertNotFound()->assertJsonPath('error.code', 'not_found');

    $this->assertDatabaseHas('orders', ['id' => $theirs->id, 'collected_amount' => '0.00']);
    expect(CashCollection::withoutTenantScope()->count())->toBe(0);
});

test('Tech §6 a dashboard session of this store is refused on another store\'s host, even for an order number that exists there', function (): void {
    $other = $this->otherTenant();
    $theirs = $this->placeOrder($this->makeCustomer(tenant: $other), [$this->orderableSku(tenant: $other)->id => 5]);

    $this->tenantJson('POST', '/api/dashboard/orders/'.$theirs->number.'/collections', ['amount' => '100'], tenant: $other)->assertUnauthorized();

    expect(CashCollection::withoutTenantScope()->count())->toBe(0);
});
