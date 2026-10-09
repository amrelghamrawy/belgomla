<?php

declare(strict_types=1);

namespace App\Modules\Customers\Http\Resources;

use App\Modules\Customers\Models\CustomerInvitation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * What the invitation page shows before the password is set (BR-CU08): the name, the phone with its middle
 * digits hidden, and when the link expires. Expects `customer` loaded.
 *
 * @property-read CustomerInvitation $resource
 */
final class InvitationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $invitation = $this->resource;
        $phone = $invitation->customer->phone;

        return [
            'name' => $invitation->customer->name,
            'phone_masked' => mb_substr($phone, 0, 6).str_repeat('*', max(0, mb_strlen($phone) - 9)).mb_substr($phone, -3),
            'expires_at' => $invitation->expires_at->toIso8601String(),
        ];
    }
}
