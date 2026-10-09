<?php

declare(strict_types=1);

use App\Modules\Orders\Models\TenantOrderCounter;
use App\Modules\Orders\Services\OrderNumbers;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    app(TenantContext::class)->set($this->tenant);
    $this->next = fn (): int => DB::transaction(fn (): int => app(OrderNumbers::class)->next());
});

test('BR-O09 the first order of a tenant is number 1 and the counter row is created', function (): void {
    expect(($this->next)())->toBe(1)
        ->and(TenantOrderCounter::query()->sole()->only(['tenant_id', 'next_number']))->toBe(['tenant_id' => $this->tenant->id, 'next_number' => 2]);
});

test('BR-O09 numbers follow each other without gaps', function (): void {
    expect([($this->next)(), ($this->next)(), ($this->next)()])->toBe([1, 2, 3]);
});

test('BR-O09 each tenant counts on its own', function (): void {
    ($this->next)();
    ($this->next)();

    $theirs = $this->inTenant(fn (): int => ($this->next)(), $this->otherTenant());

    expect($theirs)->toBe(1)
        ->and(($this->next)())->toBe(3);
});

test('BR-O09 a counter carried over continues from its value', function (): void {
    TenantOrderCounter::factory()->create(['next_number' => 1024]);

    expect(($this->next)())->toBe(1024);
});

test('Tech §11 numbers are only taken inside a transaction', function (): void {
    // RefreshDatabase wraps the default connection in a transaction, so use a fresh session without one.
    onSession('pgsql_session_a', fn (): int => app(OrderNumbers::class)->next());
})->throws(RuntimeException::class, 'inside the checkout transaction');
