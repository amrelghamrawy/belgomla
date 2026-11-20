<?php

declare(strict_types=1);

use App\Modules\Collections\Models\CashCollection;
use App\Modules\Collections\Models\CustomerCredit;
use App\Modules\Customers\Models\Customer;
use App\Modules\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;

test('to array', function (): void {
    $credit = $this->inTenant(fn (): ?CustomerCredit => CustomerCredit::factory()->create()->fresh());

    expect(array_keys($credit?->toArray() ?? []))->toBe([
        'id', 'tenant_id', 'customer_id', 'source_return_id', 'amount', 'remaining', 'note', 'created_by', 'voided_at', 'void_reason', 'voided_by', 'created_at', 'updated_at',
    ]);
});

test('casts: money as 2-decimal strings, immutable dates and integer ids', function (): void {
    $credit = $this->inTenant(fn (): ?CustomerCredit => CustomerCredit::factory()->voided()->create(['amount' => '75.5', 'remaining' => 20])->fresh());

    expect($credit?->only(['amount', 'remaining']))->toBe(['amount' => '75.50', 'remaining' => '20.00'])
        ->and($credit?->voided_at)->toBeInstanceOf(CarbonImmutable::class)
        ->and($credit?->created_at)->toBeInstanceOf(CarbonImmutable::class);
});

test('BR-C07 the database keeps 0 ≤ remaining ≤ amount, a positive amount and a void with its reason', function (array $attributes): void {
    expect(fn () => $this->inTenant(fn (): CustomerCredit => CustomerCredit::factory()->create($attributes)))->toThrow(QueryException::class);
})->with([
    'zero amount' => [['amount' => '0.00', 'remaining' => '0.00']],
    'remaining above amount' => [['amount' => '10.00', 'remaining' => '10.01']],
    'negative remaining' => [['amount' => '10.00', 'remaining' => '-1.00']],
    'void without reason' => [['voided_at' => now()]],
]);

test('a credit belongs to its customer', function (): void {
    app(TenantContext::class)->set($this->tenant);
    $customer = Customer::factory()->create();

    expect(CustomerCredit::factory()->for($customer)->create()->customer->is($customer))->toBeTrue();
});

test('BR-C07 a credit collection points at its credit, and the database ties method credit to it', function (): void {
    app(TenantContext::class)->set($this->tenant);
    $credit = CustomerCredit::factory()->create();

    expect(CashCollection::factory()->create(['method' => 'credit', 'customer_credit_id' => $credit->id])->customerCredit?->is($credit))->toBeTrue()
        ->and(fn () => CashCollection::factory()->create(['method' => 'credit']))->toThrow(QueryException::class)
        ->and(fn () => CashCollection::factory()->create(['method' => 'cash', 'customer_credit_id' => $credit->id]))->toThrow(QueryException::class);
});

test('BR-T03 a credit belongs to the tenant in context and is hidden from others', function (): void {
    $credit = $this->inTenant(fn (): CustomerCredit => CustomerCredit::factory()->create());

    expect($credit->tenant_id)->toBe($this->tenant->id)
        ->and($this->inTenant(fn (): int => CustomerCredit::query()->count(), $this->otherTenant()))->toBe(0)
        ->and(fn () => CustomerCredit::factory()->create())->toThrow(RuntimeException::class, 'without a tenant in context');
});
