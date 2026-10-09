<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Services;

use App\Modules\Messaging\Models\OutboundMessage;
use App\Modules\Tenancy\TenantContext;

/**
 * Queued work and webhooks start without a tenant. This loads a message by id across tenants and runs the
 * callback inside the message's own tenant, so every write keeps BelongsToTenant's guarantees.
 */
final readonly class MessageContext
{
    public function __construct(private TenantContext $context) {}

    /**
     * @param  callable(OutboundMessage): void  $callback
     */
    public function run(int $messageId, callable $callback): void
    {
        // Platform code: the tenant is not known until the row is read (rules/00 "Never").
        $message = OutboundMessage::withoutTenantScope()->with('tenant')->find($messageId);

        if (! $message instanceof OutboundMessage || $message->tenant === null) {
            return;
        }

        $tenant = $message->tenant;

        $this->context->runAs($tenant, function () use ($message, $callback): void {
            $callback($message);
        });
    }
}
