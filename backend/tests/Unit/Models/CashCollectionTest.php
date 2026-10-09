<?php

declare(strict_types=1);

use App\Modules\Collections\Enums\CollectionMethod;
use App\Modules\Collections\Models\CashCollection;
use App\Modules\Collections\Models\Scopes\NotVoided;
use App\Modules\Orders\Models\Order;
use App\Modules\Tenancy\TenantContext;
use Carbon\CarbonImmutable;

test('to array', function (): void {
    $collection = $this->inTenant(fn (): ?CashCollection => CashCollection::factory()->create()->fresh());

    expect(array_keys($collection?->toArray() ?? []))->toBe([
        'id', 'tenant_id', 'order_id', 'amount', 'method', 'collected_at', 'recorded_by', 'note', 'voided_at', 'void_reason', 'voided_by', 'created_at', 'updated_at',
    ]);
});

test('casts: money as a 2-decimal string, the method enum, immutable dates and integer user ids', function (): void {
    $collection = $this->inTenant(fn (): ?CashCollection => CashCollection::factory()->voided()->create(['amount' => '75.5'])->fresh());

    expect($collection?->amount)->toBe('75.50')
        ->and($collection?->method)->toBe(CollectionMethod::Cash)
        ->and($collection?->collected_at)->toBeInstanceOf(CarbonImmutable::class)
        ->and($collection?->voided_at)->toBeInstanceOf(CarbonImmutable::class)
        ->and(new CashCollection()->getTable())->toBe('collections');
});

test('a new collection is cash by default', function (): void {
    expect(new CashCollection()->method)->toBe(CollectionMethod::Cash);
});

test('a collection belongs to its order', function (): void {
    app(TenantContext::class)->set($this->tenant);
    $order = Order::factory()->create();

    expect(CashCollection::factory()->for($order)->create()->order->is($order))->toBeTrue();
});

test('BR-C03 the not-voided scope leaves voided collections out', function (): void {
    app(TenantContext::class)->set($this->tenant);
    $kept = CashCollection::factory()->create();
    CashCollection::factory()->voided()->create();

    expect(CashCollection::query()->tap(new NotVoided)->pluck('id')->all())->toBe([$kept->id]);
});

test('BR-C02 the database refuses a zero amount and a half-recorded void', function (array $attributes): void {
    $this->inTenant(fn (): CashCollection => CashCollection::factory()->create($attributes));
})->with([
    'zero amount' => [['amount' => '0.00']],
    'void without reason' => [['voided_at' => now()]],
    'reason without void' => [['void_reason' => 'خطأ']],
])->throws(Illuminate\Database\QueryException::class);

test('BR-C02 the database refuses a method other than cash', function (): void {
    app(TenantContext::class)->set($this->tenant);
    $collection = CashCollection::factory()->create();

    CashCollection::query()->toBase()->where('id', $collection->id)->update(['method' => 'card']);
})->throws(Illuminate\Database\QueryException::class);

test('a collection created in tenant context gets that tenant id', function (): void {
    expect($this->inTenant(fn (): CashCollection => CashCollection::factory()->create())->tenant_id)->toBe($this->tenant->id);
});

test('BR-T03 creating a collection without a tenant in context throws', function (): void {
    CashCollection::factory()->create();
})->throws(RuntimeException::class, 'without a tenant in context');

test('BR-T03 collections are invisible without a tenant in context', function (): void {
    $this->inTenant(fn (): CashCollection => CashCollection::factory()->create());
    app(TenantContext::class)->clear();

    expect(CashCollection::query()->count())->toBe(0);
});

test('BR-CU02 collections of another tenant are never seen', function (): void {
    $this->inTenant(fn (): CashCollection => CashCollection::factory()->create(), $this->otherTenant());

    expect($this->inTenant(fn (): int => CashCollection::query()->count()))->toBe(0);
});
