<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Http\Requests;

use App\Modules\Messaging\Data\GatewayEventData;
use Illuminate\Foundation\Http\FormRequest;

/** POST /api/webhooks/wa-gateway: `{ event, session_key, data, sent_at }` (D70, wa-gateway/README.md). */
final class WaGatewayWebhookRequest extends FormRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'event' => ['required', 'string', 'in:'.implode(',', [GatewayEventData::STARTED, GatewayEventData::OPEN, GatewayEventData::CLOSED, GatewayEventData::MESSAGE_STATUS])],
            'session_key' => ['nullable', 'uuid', 'required_unless:event,'.GatewayEventData::STARTED],
            'data' => ['present', 'array'],
            'data.phone' => ['nullable', 'string', 'regex:/^\+[1-9]\d{7,14}$/'],
            'data.reason' => ['nullable', 'string', 'max:50'],
            'data.fatal' => ['sometimes', 'boolean'],
            'data.id' => ['nullable', 'string', 'max:255', 'required_if:event,'.GatewayEventData::MESSAGE_STATUS],
            'data.status' => ['nullable', 'string', 'in:delivered,read,failed', 'required_if:event,'.GatewayEventData::MESSAGE_STATUS],
        ];
    }

    public function event(): string
    {
        return $this->string('event')->toString();
    }

    public function sessionKey(): ?string
    {
        return $this->filled('session_key') ? $this->string('session_key')->toString() : null;
    }

    public function dataString(string $key): ?string
    {
        $value = $this->input("data.{$key}");

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function fatal(): bool
    {
        return $this->boolean('data.fatal');
    }
}
