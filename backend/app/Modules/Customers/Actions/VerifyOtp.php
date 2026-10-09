<?php

declare(strict_types=1);

namespace App\Modules\Customers\Actions;

use App\Modules\Customers\Enums\CustomerStatus;
use App\Modules\Customers\Enums\OtpPurpose;
use App\Modules\Customers\Exceptions\OtpInvalid;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Services\CustomerSession;
use App\Modules\Tenancy\Queries\CurrentTenantSettingsQuery;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\DB;

/**
 * BR-CU07. For `register`: consumes the code, marks the phone verified, makes the customer `active` — or
 * `pending_approval` when the tenant requires approval (D2, D15) — and starts their session, so they can see
 * their status. For `password_reset`: only checks the code (counting the attempt) without using it up, so the
 * storefront can move on to the new-password step; ResetPassword consumes it. Returns the registered customer,
 * or null for a password-reset check.
 */
final readonly class VerifyOtp
{
    public function __construct(
        private CheckOtp $check,
        private CurrentTenantSettingsQuery $settings,
        private CustomerSession $session,
    ) {}

    public function handle(string $phone, OtpPurpose $purpose, string $code, Session $session): ?Customer
    {
        if ($purpose === OtpPurpose::PasswordReset) {
            $this->check->handle($phone, $purpose, $code, consume: false);

            return null;
        }

        $customer = Customer::query()->where('phone', $phone)->where('status', CustomerStatus::PendingVerification)->first();

        if (! $customer instanceof Customer) {
            throw OtpInvalid::noLiveCode();
        }

        $this->check->handle($phone, $purpose, $code);

        DB::transaction(function () use ($customer): void {
            $customer->forceFill([
                'status' => $this->settings->requiresCustomerApproval() ? CustomerStatus::PendingApproval : CustomerStatus::Active,
                'phone_verified_at' => now(),
            ])->save();
        });

        $this->session->start($customer, $session);

        return $customer;
    }
}
