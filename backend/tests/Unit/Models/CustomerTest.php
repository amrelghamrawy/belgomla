<?php

declare(strict_types=1);

use App\Modules\Customers\Enums\CustomerStatus;
use App\Modules\Customers\Models\Cart;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\CustomerAddress;
use App\Modules\Customers\Models\CustomerInvitation;
use App\Modules\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

beforeEach(function (): void {
    app(TenantContext::class)->set($this->tenant);
});

test('to array never exposes the password or remember token', function (): void {
    $customer = Customer::factory()->blocked()->create(['created_by' => $this->makeOwner()->id])->fresh();

    expect(array_keys($customer?->toArray() ?? []))->toBe([
        'id', 'tenant_id', 'name', 'phone', 'status', 'phone_verified_at', 'created_by', 'approved_by',
        'blocked_at', 'blocked_reason', 'status_before_block', 'created_at', 'updated_at',
    ]);
});

test('casts: status enums, dates, a hashed password', function (): void {
    $customer = Customer::factory()->blocked(CustomerStatus::PendingApproval)->create(['password' => 'secret123'])->fresh();

    expect($customer?->status)->toBe(CustomerStatus::Blocked)
        ->and($customer?->status_before_block)->toBe(CustomerStatus::PendingApproval)
        ->and($customer?->blocked_at)->toBeInstanceOf(CarbonImmutable::class)
        ->and($customer?->phone_verified_at)->toBeInstanceOf(CarbonImmutable::class)
        ->and($customer?->password)->not->toBe('secret123')
        ->and(Hash::check('secret123', (string) $customer?->password))->toBeTrue();
});

test('BR-CU04 the phone is stored in E.164 whatever the spelling', function (string $typed): void {
    expect(Customer::factory()->create(['phone' => $typed])->fresh()?->phone)->toBe('+201012345678');
})->with(['local' => ['01012345678'], 'spaced' => ['010 1234 5678'], 'arabic digits' => ['٠١٠١٢٣٤٥٦٧٨']]);

test('a phone that is not an egyptian mobile never reaches the database', function (): void {
    Customer::factory()->create(['phone' => '0223456789']);
})->throws(InvalidArgumentException::class, 'is not an Egyptian mobile number');

test('BR-CU04 the phone is unique per tenant', function (): void {
    Customer::factory()->create(['phone' => '01012345678']);

    Customer::factory()->create(['phone' => '+201012345678']);
})->throws(QueryException::class, 'customers_tenant_id_phone_unique');

test('the status must be a known one', function (): void {
    $customer = Customer::factory()->create();

    DB::table('customers')->where('id', $customer->id)->update(['status' => 'vip']);
})->throws(QueryException::class, 'customers_status_known');

test('relations: addresses (default first), invitation and cart', function (): void {
    $customer = Customer::factory()->invited()->create();
    $other = CustomerAddress::factory()->for($customer)->create();
    $default = CustomerAddress::factory()->for($customer)->default()->create();
    $invitation = CustomerInvitation::factory()->for($customer)->create();
    $cart = Cart::factory()->for($customer)->create();

    expect($customer->addresses->pluck('id')->all())->toBe([$default->id, $other->id])
        ->and($customer->invitation?->is($invitation))->toBeTrue()
        ->and($customer->cart?->is($cart))->toBeTrue();
});

test('factory states cover every status', function (string $state, CustomerStatus $status): void {
    expect(Customer::factory()->{$state}()->create()->status)->toBe($status);
})->with([
    'pending verification' => ['pendingVerification', CustomerStatus::PendingVerification],
    'invited' => ['invited', CustomerStatus::Invited],
    'pending approval' => ['pendingApproval', CustomerStatus::PendingApproval],
    'blocked' => ['blocked', CustomerStatus::Blocked],
]);

test('an invited customer has no password and an unverified phone', function (): void {
    $customer = Customer::factory()->invited()->create()->fresh();

    expect($customer?->password)->toBeNull()
        ->and($customer?->phone_verified_at)->toBeNull();
});
