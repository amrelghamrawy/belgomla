<?php

declare(strict_types=1);

namespace App\Modules\Customers\Actions;

use App\Modules\Customers\Enums\CustomerStatus;
use App\Modules\Customers\Enums\OtpPurpose;
use App\Modules\Customers\Exceptions\OtpInvalid;
use App\Modules\Customers\Models\Customer;
use Illuminate\Support\Str;

/**
 * BR-CU04: sets a new password with a `password_reset` code (BR-CU07), using the code up. Only customers who
 * could ask for one (`active`, `pending_approval`) can reset; the customer logs in afterwards. The remember
 * token is rotated so "remember me" cookies made with the old password stop working.
 */
final readonly class ResetPassword
{
    public function __construct(private CheckOtp $check) {}

    public function handle(string $phone, string $code, string $password): Customer
    {
        $customer = Customer::query()
            ->where('phone', $phone)
            ->whereIn('status', [CustomerStatus::Active, CustomerStatus::PendingApproval])
            ->first();

        if (! $customer instanceof Customer) {
            throw OtpInvalid::noLiveCode();
        }

        $this->check->handle($phone, OtpPurpose::PasswordReset, $code);

        $customer->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();

        return $customer;
    }
}
