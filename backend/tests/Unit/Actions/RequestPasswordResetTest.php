<?php

declare(strict_types=1);

use App\Modules\Customers\Actions\RequestPasswordReset;
use App\Modules\Customers\Enums\CustomerStatus;
use App\Modules\Customers\Enums\OtpPurpose;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\CustomerOtp;
use App\Modules\Messaging\Enums\Channel;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    Queue::fake();
    app(TenantContext::class)->set($this->tenant);
});

test('BR-CU04 an active or pending-approval customer gets a password_reset code', function (CustomerStatus $status): void {
    Customer::factory()->create(['phone' => '+201012345678', 'status' => $status]);

    expect(app(RequestPasswordReset::class)->handle('+201012345678'))->toBe(Channel::WhatsApp)
        ->and(CustomerOtp::query()->sole()->purpose)->toBe(OtpPurpose::PasswordReset);
})->with(['active' => [CustomerStatus::Active], 'pending approval' => [CustomerStatus::PendingApproval]]);

test('the code can go by sms', function (): void {
    Customer::factory()->create(['phone' => '+201012345678']);

    expect(app(RequestPasswordReset::class)->handle('+201012345678', Channel::Sms))->toBe(Channel::Sms);
});

test('D52 nobody else gets a code', function (CustomerStatus $status): void {
    Customer::factory()->create(['phone' => '+201012345678', 'status' => $status]);

    app(RequestPasswordReset::class)->handle('+201012345678');

    expect(CustomerOtp::query()->count())->toBe(0);
})->with(['blocked' => [CustomerStatus::Blocked], 'invited' => [CustomerStatus::Invited], 'pending verification' => [CustomerStatus::PendingVerification]]);
