<?php

declare(strict_types=1);

use App\Modules\Customers\Actions\ActivateInvitation;
use App\Modules\Customers\Enums\CustomerStatus;
use App\Modules\Customers\Exceptions\InvitationInvalid;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\CustomerInvitation;
use App\Modules\Tenancy\Models\TenantSetting;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

beforeEach(function (): void {
    app(TenantContext::class)->set($this->tenant);
    $this->customer = Customer::factory()->invited()->create();
    $this->invitation = CustomerInvitation::factory()->for($this->customer)->token('tok')->create();
});

test('BR-CU08 the link sets the password, verifies the phone, activates, is used up and logs in', function (): void {
    $customer = app(ActivateInvitation::class)->handle('tok', 'secret123', arraySession());

    expect($customer->status)->toBe(CustomerStatus::Active)
        ->and($customer->phone_verified_at)->not->toBeNull()
        ->and(Hash::check('secret123', (string) $customer->password))->toBeTrue()
        ->and($this->invitation->refresh()->used_at)->not->toBeNull()
        ->and(Auth::guard('customer')->id())->toBe($customer->id);
});

test('D2 with approval required the customer waits for the merchant', function (): void {
    TenantSetting::query()->whereKey($this->tenant->id)->update(['require_customer_approval' => true]);

    expect(app(ActivateInvitation::class)->handle('tok', 'secret123', arraySession())->status)->toBe(CustomerStatus::PendingApproval);
});

test('a bad link changes nothing', function (): void {
    expect(fn () => app(ActivateInvitation::class)->handle('other', 'secret123', arraySession()))->toThrow(InvitationInvalid::class);

    expect($this->customer->refresh()->status)->toBe(CustomerStatus::Invited)
        ->and(Auth::guard('customer')->check())->toBeFalse();
});
