<?php

declare(strict_types=1);

namespace App\Modules\Customers\Actions;

use App\Modules\Customers\Enums\OtpPurpose;
use App\Modules\Messaging\Enums\Channel;

/**
 * BR-CU04: "forgot password" sends a `password_reset` code to the phone (BR-CU07). An unknown number gets the
 * same answer and no message (D52).
 */
final readonly class RequestPasswordReset
{
    public function __construct(private SendOtp $sendOtp) {}

    public function handle(string $phone, Channel $channel = Channel::WhatsApp): Channel
    {
        return $this->sendOtp->handle($phone, OtpPurpose::PasswordReset, $channel);
    }
}
