<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Queries;

use App\Modules\Messaging\Enums\Channel;
use App\Modules\Messaging\Models\OutboundMessage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * Finds the message a provider webhook is about. Webhooks arrive on the platform `hooks` host without a
 * tenant (08-api-contract.md §13), so the lookup spans tenants by design; the caller then works inside the
 * message's tenant (MessageContext).
 */
final readonly class OutboundMessageByProviderIdQuery
{
    public function find(Channel $channel, ?string $messageId, ?string $requestId): ?OutboundMessage
    {
        if ($messageId === null && $requestId === null) {
            return null;
        }

        // Platform code: the tenant is what this lookup discovers (rules/00 "Never").
        return OutboundMessage::withoutTenantScope()
            ->where('channel', $channel)
            ->where(function (Builder $query) use ($messageId, $requestId): void {
                if ($messageId !== null) {
                    $query->orWhere('provider_message_id', $messageId);
                }

                if ($requestId !== null) {
                    $query->orWhere('provider_request_id', $requestId);
                }

                if ($requestId !== null && Str::isUuid($requestId)) {
                    $query->orWhere('uuid', $requestId); // SMS request_id is our uuid
                }
            })
            ->first();
    }
}
