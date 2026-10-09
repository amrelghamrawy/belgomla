<?php

declare(strict_types=1);

use App\Modules\Orders\Models\TenantOrderCounter;
use App\Modules\Tenancy\TenantContext;

beforeEach(function (): void {
    app(TenantContext::class)->set($this->tenant);
});

test('to array', function (): void {
    $counter = TenantOrderCounter::factory()->create()->fresh();

    expect(array_keys($counter?->toArray() ?? []))->toBe(['tenant_id', 'next_number', 'created_at', 'updated_at']);
});

test('BR-O09 one counter per tenant, keyed by the tenant, starting at 1', function (): void {
    $counter = TenantOrderCounter::factory()->create()->fresh();

    expect($counter?->getKey())->toBe($this->tenant->id)
        ->and($counter?->next_number)->toBe(1)
        ->and(new TenantOrderCounter()->getIncrementing())->toBeFalse();
});
