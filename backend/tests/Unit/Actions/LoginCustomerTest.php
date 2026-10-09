<?php

declare(strict_types=1);

use App\Modules\Customers\Actions\LoginCustomer;
use App\Modules\Customers\Enums\CustomerStatus;
use App\Modules\Customers\Exceptions\LoginRejected;
use App\Modules\Customers\Models\Customer;
use App\Modules\Tenancy\Http\Middleware\EnsureSessionTenant;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;

beforeEach(function (): void {
    app(TenantContext::class)->set($this->tenant);
    $this->customer = Customer::factory()->create(['phone' => '+201012345678', 'password' => 'secret123']);
});

test('BR-CU04 the right password starts a tenant-bound session', function (): void {
    $session = arraySession();

    $customer = app(LoginCustomer::class)->handle('+201012345678', 'secret123', '10.0.0.1', $session);

    expect($customer->is($this->customer))->toBeTrue()
        ->and(Auth::guard('customer')->id())->toBe($this->customer->id)
        ->and($session->get(EnsureSessionTenant::SESSION_KEY))->toBe($this->tenant->id);
});

test('a wrong password is counted against the phone and ip', function (): void {
    expect(fn () => app(LoginCustomer::class)->handle('+201012345678', 'nope', '10.0.0.1', arraySession()))
        ->toThrow(LoginRejected::class, 'رقم الهاتف أو كلمة المرور غير صحيحة');

    expect(RateLimiter::attempts('store-login:'.$this->tenant->id.':+201012345678|10.0.0.1'))->toBe(1);
});

test('a successful login clears the failures', function (): void {
    RateLimiter::hit('store-login:'.$this->tenant->id.':+201012345678|10.0.0.1');

    app(LoginCustomer::class)->handle('+201012345678', 'secret123', '10.0.0.1', arraySession());

    expect(RateLimiter::attempts('store-login:'.$this->tenant->id.':+201012345678|10.0.0.1'))->toBe(0);
});

test('five failures lock the phone for that ip only', function (): void {
    foreach (range(1, 5) as $failure) {
        RateLimiter::hit('store-login:'.$this->tenant->id.':+201012345678|10.0.0.1');
    }

    expect(fn () => app(LoginCustomer::class)->handle('+201012345678', 'secret123', '10.0.0.1', arraySession()))
        ->toThrow(LoginRejected::class, 'محاولات كثيرة، حاول بعد دقيقة');
    expect(app(LoginCustomer::class)->handle('+201012345678', 'secret123', '10.0.0.2', arraySession())->id)->toBe($this->customer->id);
});

test('BR-CU05 a customer who is not active gets no session', function (CustomerStatus $status): void {
    $this->customer->forceFill(['status' => $status])->save();

    expect(fn () => app(LoginCustomer::class)->handle('+201012345678', 'secret123', '10.0.0.1', arraySession()))->toThrow(LoginRejected::class);

    expect(Auth::guard('customer')->check())->toBeFalse();
})->with(['pending approval' => [CustomerStatus::PendingApproval], 'blocked' => [CustomerStatus::Blocked], 'pending verification' => [CustomerStatus::PendingVerification]]);

test('BR-CU08 a customer without a password cannot log in', function (): void {
    $this->customer->forceFill(['password' => null])->save();

    app(LoginCustomer::class)->handle('+201012345678', '', '10.0.0.1', arraySession());
})->throws(LoginRejected::class, 'رقم الهاتف أو كلمة المرور غير صحيحة');
