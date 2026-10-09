<?php

declare(strict_types=1);

use App\Modules\Audit\Models\AuditLog;
use App\Modules\Collections\Actions\RecordCollection;
use App\Modules\Collections\Data\CollectionData;
use App\Modules\Collections\Models\CashCollection;

beforeEach(function (): void {
    $this->owner = $this->actingAsOwner();
    $this->order = $this->placeOrder($this->makeCustomer(), [$this->orderableSku()->id => 6]);
    $this->collection = $this->inTenant(fn (): CashCollection => app(RecordCollection::class)->handle($this->order, new CollectionData('540.00'), $this->owner->id));
    $this->void = fn (array $body, ?int $id = null, array $headers = []) => $this->tenantJson('POST', '/api/dashboard/collections/'.($id ?? $this->collection->id).'/void', $body, headers: $headers);
});

test('BR-C02 the merchant voids a collection with a reason and the order balance follows', function (): void {
    $this->freezeSecond();

    ($this->void)(['reason' => 'تسجيل مكرر'])
        ->assertOk()
        ->assertJsonPath('data.id', $this->collection->id)
        ->assertJsonPath('data.is_voided', true)
        ->assertJsonPath('data.voided_at', now()->toIso8601String())
        ->assertJsonPath('data.void_reason', 'تسجيل مكرر')
        ->assertJsonPath('data.voided_by', $this->owner->id)
        ->assertJsonPath('data.order.collected_amount', '0.00')
        ->assertJsonPath('data.order.collection_status', 'unpaid');

    $this->assertDatabaseHas('collections', ['id' => $this->collection->id, 'amount' => '540.00', 'void_reason' => 'تسجيل مكرر']);
});

test('BR-L voiding is audited', function (): void {
    ($this->void)(['reason' => 'تسجيل مكرر'])->assertOk();

    expect(AuditLog::withoutTenantScope()->where('action', 'collection.voided')->sole()->only(['actor_id', 'entity_id', 'old_values']))
        ->toEqual(['actor_id' => $this->owner->id, 'entity_id' => $this->collection->id, 'old_values' => ['voided_at' => null]]);
});

test('BR-O03 once its collections are voided the order can be cancelled', function (): void {
    $this->tenantJson('POST', '/api/dashboard/orders/'.$this->order->number.'/cancel', ['reason' => 'خطأ'])->assertConflict()->assertJsonPath('error.code', 'has_collections');

    ($this->void)(['reason' => 'تسجيل خاطئ'])->assertOk();

    $this->tenantJson('POST', '/api/dashboard/orders/'.$this->order->number.'/cancel', ['reason' => 'خطأ'])->assertOk()->assertJsonPath('data.status', 'cancelled');
});

test('BR-C02 a second void is 409 already_voided', function (): void {
    ($this->void)(['reason' => 'تسجيل مكرر'])->assertOk();

    ($this->void)(['reason' => 'مرة أخرى'])->assertConflict()->assertJsonPath('error.code', 'already_voided');
});

test('the void reason is required and bounded', function (array $body): void {
    ($this->void)($body)
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonStructure(['error' => ['details' => ['reason']]]);
})->with([
    'missing' => [[]],
    'blank' => [['reason' => '']],
    'too long' => [['reason' => str_repeat('س', 301)]],
]);

test('D55 the same Idempotency-Key twice answers the first void again', function (): void {
    ($this->void)(['reason' => 'مكرر'], headers: ['Idempotency-Key' => 'void-1'])->assertOk();

    ($this->void)(['reason' => 'مكرر'], headers: ['Idempotency-Key' => 'void-1'])->assertOk()->assertHeader('Idempotent-Replayed', 'true');
});

test('BR-T04 a collection of another tenant is 404 and stays as it was', function (): void {
    $other = $this->otherTenant();
    $theirs = $this->inTenant(fn (): CashCollection => CashCollection::factory()->create(), $other);

    ($this->void)(['reason' => 'مكرر'], $theirs->id)->assertNotFound()->assertJsonPath('error.code', 'not_found');

    $this->assertDatabaseHas('collections', ['id' => $theirs->id, 'voided_at' => null]);
});

test('Tech §6 a dashboard session of this store is refused on another store\'s host, even for a collection that exists there', function (): void {
    $other = $this->otherTenant();
    $theirs = $this->inTenant(fn (): CashCollection => CashCollection::factory()->create(), $other);

    $this->tenantJson('POST', '/api/dashboard/collections/'.$theirs->id.'/void', ['reason' => 'مكرر'], tenant: $other)->assertUnauthorized();

    $this->assertDatabaseHas('collections', ['id' => $theirs->id, 'voided_at' => null]);
});
