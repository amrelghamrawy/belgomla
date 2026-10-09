<?php

declare(strict_types=1);

use App\Modules\Customers\Enums\CustomerStatus;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\CustomerInvitation;
use App\Modules\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;

beforeEach(function (): void {
    app(TenantContext::class)->set($this->tenant);
});

test('to array never exposes the token hash', function (): void {
    $invitation = CustomerInvitation::factory()->create()->fresh();

    expect(array_keys($invitation?->toArray() ?? []))->toBe([
        'id', 'tenant_id', 'customer_id', 'expires_at', 'used_at', 'sent_count', 'last_sent_at', 'created_at', 'updated_at',
    ]);
});

test('casts: dates and an integer send count', function (): void {
    $invitation = CustomerInvitation::factory()->used()->create()->fresh();

    expect($invitation?->sent_count)->toBe(1)
        ->and($invitation?->expires_at)->toBeInstanceOf(CarbonImmutable::class)
        ->and($invitation?->used_at)->toBeInstanceOf(CarbonImmutable::class)
        ->and($invitation?->last_sent_at)->toBeInstanceOf(CarbonImmutable::class);
});

test('an invitation belongs to an invited customer', function (): void {
    $invitation = CustomerInvitation::factory()->create();

    expect($invitation->customer)->toBeInstanceOf(Customer::class)
        ->and($invitation->customer->status)->toBe(CustomerStatus::Invited);
});

test('BR-CU08 a customer has one invitation row', function (): void {
    $customer = Customer::factory()->invited()->create();
    CustomerInvitation::factory()->for($customer)->create();

    CustomerInvitation::factory()->for($customer)->create();
})->throws(QueryException::class, 'customer_invitations_customer_id_unique');

test('token hashes are unique', function (): void {
    CustomerInvitation::factory()->token('same')->create();

    CustomerInvitation::factory()->token('same')->create();
})->throws(QueryException::class, 'customer_invitations_token_hash_unique');

test('the expired state is in the past', function (): void {
    expect(CustomerInvitation::factory()->expired()->create()->expires_at->isPast())->toBeTrue();
});
