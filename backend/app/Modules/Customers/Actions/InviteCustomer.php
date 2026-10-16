<?php

declare(strict_types=1);

namespace App\Modules\Customers\Actions;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Customers\Enums\CustomerStatus;
use App\Modules\Customers\Exceptions\InvalidCustomerTransition;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\CustomerInvitation;
use App\Modules\Customers\Services\InvitationTokens;
use App\Modules\Messaging\Data\MessagePayload;
use App\Modules\Messaging\Enums\MessagePurpose;
use App\Modules\Messaging\Services\Messenger;
use App\Modules\Tenancy\Queries\CurrentTenantSettingsQuery;
use App\Modules\Tenancy\TenantContext;
use App\Support\InvitationMessage;
use Illuminate\Support\Facades\DB;

/**
 * BR-CU08: sends (or re-sends) the invitation link of a merchant-created customer over WhatsApp, which falls
 * back to SMS on its own (BR-CU07). Each send rotates the token, so only the newest link works, and restarts
 * the 7 days. The text is the store's own (InvitationMessage, null = default). Only an `invited` customer can be
 * invited (`409`). Audited as `customer.invited`. Owns its transaction; the message is queued after commit.
 */
final readonly class InviteCustomer
{
    public function __construct(
        private InvitationTokens $tokens,
        private Messenger $messenger,
        private TenantContext $context,
        private CurrentTenantSettingsQuery $settings,
        private AuditLogger $audit,
    ) {}

    public function handle(Customer $customer): CustomerInvitation
    {
        if ($customer->status !== CustomerStatus::Invited) {
            throw InvalidCustomerTransition::notInvitable($customer->status);
        }

        return DB::transaction(function () use ($customer): CustomerInvitation {
            $token = $this->tokens->generate();

            $invitation = CustomerInvitation::query()->firstOrNew(['customer_id' => $customer->id]);
            $invitation->fill([
                'token_hash' => $this->tokens->hash($token),
                'expires_at' => now()->addDays(config()->integer('customers.invitation.ttl_days')),
                'used_at' => null,
                'sent_count' => $invitation->sent_count + 1,
                'last_sent_at' => now(),
            ])->save();

            $this->messenger->send(new MessagePayload($customer->phone, MessagePurpose::Invitation, $this->text($customer, $token)));

            $this->audit->log('customer.invited', $customer, null, ['sent_count' => $invitation->sent_count]);

            return $invitation;
        });
    }

    private function text(Customer $customer, string $token): string
    {
        return InvitationMessage::render(
            $this->settings->invitationMessage(),
            $customer->name,
            $this->context->get()->name,
            config()->integer('customers.invitation.ttl_days'),
            $this->tokens->link($token),
        );
    }
}
