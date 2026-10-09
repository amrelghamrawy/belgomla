<?php

declare(strict_types=1);

namespace App\Modules\Customers\Actions;

use App\Modules\Customers\Exceptions\LoginRejected;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Services\CustomerSession;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

/**
 * BR-CU04: storefront login with phone + password, rate-limited like the dashboard (5 failures per phone and
 * IP per minute). Only `active` customers get a session (BR-CU05); the others learn their status only after
 * the password matched, so the status of a number is never revealed to a stranger.
 */
final readonly class LoginCustomer
{
    private const int MAX_ATTEMPTS = 5;

    private const int DECAY_SECONDS = 60;

    public function __construct(
        private TenantContext $context,
        private CustomerSession $session,
    ) {}

    public function handle(string $phone, string $password, string $ip, Session $session): Customer
    {
        $key = 'store-login:'.$this->context->id().':'.$phone.'|'.$ip;

        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            throw LoginRejected::tooManyAttempts();
        }

        // Customer is tenant-scoped, so only this host's customers can be found.
        $customer = Customer::query()->where('phone', $phone)->first();

        if (! $customer instanceof Customer || $customer->password === null || ! Hash::check($password, $customer->password)) {
            RateLimiter::hit($key, self::DECAY_SECONDS);

            throw LoginRejected::invalidCredentials();
        }

        if (! $customer->status->canLogin()) {
            throw LoginRejected::notActive($customer->status);
        }

        RateLimiter::clear($key);
        $this->session->start($customer, $session);

        return $customer;
    }
}
