<?php

declare(strict_types=1);

use App\Modules\Customers\Models\Cart;
use App\Modules\Customers\Models\CartItem;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\CustomerAddress;
use App\Modules\Customers\Models\CustomerInvitation;
use App\Modules\Customers\Models\CustomerOtp;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;

/*
| BelongsToTenant behaviour for every Customers model (rules/01-testing.md §4 Models, BR-T03, BR-CU01, BR-CU02).
*/

dataset('customers models', [
    'customer' => [Customer::class],
    'address' => [CustomerAddress::class],
    'otp' => [CustomerOtp::class],
    'invitation' => [CustomerInvitation::class],
    'cart' => [Cart::class],
    'cart item' => [CartItem::class],
]);

test('BR-CU01 a model created in tenant context gets that tenant id', function (string $model): void {
    /** @var class-string<Model> $model */
    $row = $this->inTenant(fn () => $model::factory()->create());

    expect($row->tenant_id)->toBe($this->tenant->id);
})->with('customers models');

test('BR-T03 creating a model without a tenant in context throws', function (string $model): void {
    $model::factory()->create();
})->with('customers models')->throws(RuntimeException::class, 'without a tenant in context');

test('BR-T03 queries without a tenant in context return nothing', function (string $model): void {
    $this->inTenant(fn () => $model::factory()->create());
    app(TenantContext::class)->clear();

    expect($model::query()->count())->toBe(0);
})->with('customers models');

test('BR-CU02 queries in context never see another tenant', function (string $model): void {
    $this->inTenant(fn () => $model::factory()->create(), $this->otherTenant());

    expect($this->inTenant(fn () => $model::query()->count()))->toBe(0);
})->with('customers models');
