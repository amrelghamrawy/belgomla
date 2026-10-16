<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Actions;

use App\Modules\Messaging\Data\DriverResult;
use App\Modules\Messaging\Data\GatewayEventData;
use App\Modules\Messaging\Enums\Channel;
use App\Modules\Messaging\Enums\Provider;
use App\Modules\Messaging\Jobs\ReconcileWaGateway;
use App\Modules\Messaging\Models\OutboundMessage;
use App\Modules\Messaging\Models\WhatsAppConnection;
use App\Modules\Messaging\Queries\OutboundMessageByProviderIdQuery;
use App\Modules\Messaging\Queries\StoreWhatsAppConnectionQuery;
use App\Modules\Messaging\Services\MessageContext;
use App\Modules\Tenancy\TenantContext;

/**
 * Takes a verified wa-gateway webhook (D70, 08-api-contract.md §13). A restarted gateway gets its sessions
 * back through the reconcile; session events go to RecordConnectionEvent inside the store's tenant; receipts
 * of store-number messages go to RecordDeliveryStatus (a failed one falls back, BR-CU07). Events about
 * sessions or messages we do not know are acknowledged and dropped: the reconcile cleans up stray sessions.
 */
final readonly class AcceptWaGatewayWebhook
{
    public function __construct(
        private StoreWhatsAppConnectionQuery $connections,
        private OutboundMessageByProviderIdQuery $messages,
        private RecordConnectionEvent $recordConnection,
        private RecordDeliveryStatus $recordDelivery,
        private MessageContext $messageContext,
        private TenantContext $tenantContext,
    ) {}

    public function handle(GatewayEventData $event): void
    {
        match ($event->event) {
            GatewayEventData::STARTED => ReconcileWaGateway::dispatch(),
            GatewayEventData::MESSAGE_STATUS => $this->messageStatus($event),
            default => $this->sessionEvent($event),
        };
    }

    private function sessionEvent(GatewayEventData $event): void
    {
        $connection = $event->sessionKey === null ? null : $this->connections->bySessionKey($event->sessionKey);
        $tenant = $connection?->tenant;

        if (! $connection instanceof WhatsAppConnection || $tenant === null) {
            return;
        }

        $this->tenantContext->runAs($tenant, fn (): WhatsAppConnection => $this->recordConnection->handle($connection, $event));
    }

    private function messageStatus(GatewayEventData $event): void
    {
        $message = $this->messages->find(Channel::WhatsApp, $event->messageId, null);

        if ($message?->provider !== Provider::WaGateway) {
            return;
        }

        $result = $event->messageStatus === 'failed'
            ? DriverResult::failed('whatsapp_ack_error')
            : DriverResult::delivered($event->messageId);

        $this->messageContext->run($message->id, function (OutboundMessage $message) use ($result): void {
            $this->recordDelivery->handle($message, $result);
        });
    }
}
