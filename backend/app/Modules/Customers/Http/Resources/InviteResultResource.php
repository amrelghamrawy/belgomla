<?php

declare(strict_types=1);

namespace App\Modules\Customers\Http\Resources;

use App\Modules\Customers\Models\CustomerInvitation;
use App\Modules\Messaging\Enums\Channel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The answer to "send/re-send invitation" (08-api-contract.md §8): `{ sent_via, expires_at, sent_count }`.
 * Invitations go out over WhatsApp; a fallback to SMS happens later, inside Messaging (BR-CU07).
 *
 * @property-read CustomerInvitation $resource
 */
final class InviteResultResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'sent_via' => Channel::WhatsApp->value,
            'expires_at' => $this->resource->expires_at->toIso8601String(),
            'sent_count' => $this->resource->sent_count,
        ];
    }
}
