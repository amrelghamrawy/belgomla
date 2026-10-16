<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Data;

use App\Modules\Messaging\Http\Requests\WaGatewayWebhookRequest;

/**
 * One wa-gateway event (`wa-gateway/README.md`): `gateway.started`, `session.open { phone }`,
 * `session.closed { reason, fatal }` or `message.status { id, status }`.
 */
final readonly class GatewayEventData
{
    public const string STARTED = 'gateway.started';

    public const string OPEN = 'session.open';

    public const string CLOSED = 'session.closed';

    public const string MESSAGE_STATUS = 'message.status';

    public function __construct(
        public string $event,
        public ?string $sessionKey = null,
        public ?string $phone = null,
        public ?string $reason = null,
        public bool $fatal = false,
        public ?string $messageId = null,
        public ?string $messageStatus = null,
    ) {}

    public static function fromRequest(WaGatewayWebhookRequest $request): self
    {
        return new self(
            $request->event(),
            $request->sessionKey(),
            $request->dataString('phone'),
            $request->dataString('reason'),
            $request->fatal(),
            $request->dataString('id'),
            $request->dataString('status'),
        );
    }
}
