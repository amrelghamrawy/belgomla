<?php

declare(strict_types=1);

use App\Modules\Customers\Actions\RegisterCustomer;
use App\Modules\Customers\Data\RegistrationData;
use App\Modules\Customers\Enums\CustomerStatus;
use App\Modules\Customers\Exceptions\OtpSendLimited;
use App\Modules\Customers\Exceptions\PhoneTaken;
use App\Modules\Customers\Exceptions\RegistrationRejected;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\CustomerOtp;
use App\Modules\Messaging\Enums\Channel;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;

beforeEach(function (): void {
    Queue::fake();
    app(TenantContext::class)->set($this->tenant);
});

function registration(string $name = 'محمود'): RegistrationData
{
    return new RegistrationData($name, '+201012345678', 'secret123');
}

test('BR-CU04 a new phone becomes a pending-verification customer with a hashed password and gets a code', function (): void {
    expect(app(RegisterCustomer::class)->handle(registration()))->toBe(Channel::WhatsApp);

    $customer = Customer::query()->sole();
    expect($customer->status)->toBe(CustomerStatus::PendingVerification)
        ->and(Hash::check('secret123', (string) $customer->password))->toBeTrue()
        ->and(CustomerOtp::query()->count())->toBe(1);
});

test('BR-CU08 an invited phone is refused', function (): void {
    Customer::factory()->invited()->create(['phone' => '+201012345678']);

    app(RegisterCustomer::class)->handle(registration());
})->throws(RegistrationRejected::class);

test('BR-CU04 an existing active phone is refused', function (): void {
    Customer::factory()->create(['phone' => '+201012345678']);

    app(RegisterCustomer::class)->handle(registration());
})->throws(PhoneTaken::class);

test('D52 a pending phone re-registers with the new name and password', function (): void {
    Customer::factory()->pendingVerification()->create(['phone' => '+201012345678', 'name' => 'قديم', 'password' => 'old-secret']);

    app(RegisterCustomer::class)->handle(registration('جديد'));

    $customer = Customer::query()->sole();
    expect($customer->name)->toBe('جديد')
        ->and(Hash::check('secret123', (string) $customer->password))->toBeTrue();
});

test('when the code cannot be sent nothing is created', function (): void {
    foreach (range(1, 5) as $sent) {
        RateLimiter::hit('customer-otp:'.$this->tenant->id.':+201012345678', 3600);
    }

    expect(fn () => app(RegisterCustomer::class)->handle(registration()))->toThrow(OtpSendLimited::class);

    expect(Customer::query()->count())->toBe(0);
});
