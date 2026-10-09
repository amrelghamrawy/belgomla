<?php

declare(strict_types=1);

namespace App\Modules\Customers\Queries;

use App\Modules\Customers\Enums\CustomerStatus;
use App\Modules\Customers\Exceptions\InvitationInvalid;
use App\Modules\Customers\Models\CustomerInvitation;
use App\Modules\Customers\Services\InvitationTokens;

/**
 * BR-CU08: the live invitation behind a link, with its customer. Unknown, superseded, used or expired links,
 * and customers who are no longer `invited`, are all `404` (InvitationInvalid). Tenant-scoped.
 */
final readonly class InvitationByTokenQuery
{
    public function __construct(private InvitationTokens $tokens) {}

    public function live(string $token): CustomerInvitation
    {
        $invitation = CustomerInvitation::query()
            ->with('customer')
            ->where('token_hash', $this->tokens->hash($token))
            ->first();

        if (! $invitation instanceof CustomerInvitation
            || $invitation->used_at !== null
            || $invitation->expires_at->isPast()
            || $invitation->customer->status !== CustomerStatus::Invited) {
            throw InvitationInvalid::make();
        }

        return $invitation;
    }
}
