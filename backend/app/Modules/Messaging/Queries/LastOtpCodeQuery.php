<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Queries;

use App\Modules\Messaging\Enums\MessagePurpose;
use App\Modules\Messaging\Models\OutboundMessage;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * D62: the newest OTP sent to a phone on this tenant, read back from the still-encrypted message body. Only the
 * local/testing route `GET /api/_test/last-otp` uses it, so Playwright can finish the register flow (tasks/9).
 */
final readonly class LastOtpCodeQuery
{
    /**
     * @throws ModelNotFoundException<OutboundMessage> when no OTP with a readable code was sent to the phone
     */
    public function get(string $phone): string
    {
        $body = OutboundMessage::query()
            ->where('to_phone', $phone)
            ->where('purpose', MessagePurpose::Otp)
            ->whereNotNull('body')
            ->latest('id')
            ->firstOrFail()
            ->body;

        if ($body === null || preg_match('/\b(\d{6})\b/', $body, $match) !== 1) {
            throw new ModelNotFoundException()->setModel(OutboundMessage::class);
        }

        return $match[1];
    }
}
