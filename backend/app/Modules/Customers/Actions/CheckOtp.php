<?php

declare(strict_types=1);

namespace App\Modules\Customers\Actions;

use App\Modules\Customers\Enums\OtpPurpose;
use App\Modules\Customers\Exceptions\OtpAttemptsExceeded;
use App\Modules\Customers\Exceptions\OtpExpired;
use App\Modules\Customers\Exceptions\OtpInvalid;
use App\Modules\Customers\Models\CustomerOtp;
use App\Modules\Customers\Services\OtpCodes;

/**
 * BR-CU07: checks a code against the latest live one for the phone and purpose: valid 10 minutes, 5 attempts,
 * single use. A wrong code counts an attempt and that count must survive the refusal, so this step runs
 * outside any transaction (callers check first, then open theirs). Consuming is atomic: of two requests with
 * the same code only one succeeds.
 */
final readonly class CheckOtp
{
    public function __construct(private OtpCodes $codes) {}

    public function handle(string $phone, OtpPurpose $purpose, string $code, bool $consume = true): CustomerOtp
    {
        $otp = CustomerOtp::query()
            ->where('phone', $phone)
            ->where('purpose', $purpose)
            ->whereNull('consumed_at')
            ->latest('id')
            ->first();

        if (! $otp instanceof CustomerOtp) {
            throw OtpInvalid::noLiveCode();
        }

        if ($otp->expires_at->isPast()) {
            throw OtpExpired::make();
        }

        $maxAttempts = config()->integer('customers.otp.max_attempts');

        if ($otp->attempts >= $maxAttempts) {
            throw OtpAttemptsExceeded::make();
        }

        if (! $this->codes->matches($code, $otp->code_hash)) {
            $otp->increment('attempts');

            throw OtpInvalid::wrongCode(max(0, $maxAttempts - $otp->attempts));
        }

        if ($consume && CustomerOtp::query()->whereKey($otp->id)->whereNull('consumed_at')->update(['consumed_at' => now()]) !== 1) {
            throw OtpInvalid::noLiveCode();
        }

        return $otp;
    }
}
