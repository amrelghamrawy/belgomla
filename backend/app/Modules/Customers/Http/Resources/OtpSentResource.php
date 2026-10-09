<?php

declare(strict_types=1);

namespace App\Modules\Customers\Http\Resources;

use App\Modules\Messaging\Enums\Channel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * BR-CU07: the channel a code was sent on, `{ otp_sent_via: "whatsapp" | "sms" }`. Messaging may still fall
 * back from WhatsApp to SMS after this answer.
 *
 * @property-read Channel $resource
 */
final class OtpSentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'otp_sent_via' => $this->resource->value,
        ];
    }
}
