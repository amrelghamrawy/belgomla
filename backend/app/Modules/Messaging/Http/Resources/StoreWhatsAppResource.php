<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Http\Resources;

use App\Modules\Messaging\Data\StoreWhatsAppStatus;
use App\Modules\Messaging\Enums\ConnectionStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The store's WhatsApp number on the settings page (D70). A store that never linked one is `disconnected`.
 * `qr` is a PNG data URL, present only while pairing; `logged_out_on_phone` only answers an unlink.
 *
 * @property-read StoreWhatsAppStatus $resource
 */
final class StoreWhatsAppResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $connection = $this->resource->connection;
        $status = $connection->status ?? ConnectionStatus::Disconnected;

        return [
            'enabled' => $this->resource->enabled,
            'status' => $status->value,
            'status_label' => $status->label(),
            'phone' => $connection?->phone,
            'connected_at' => $connection?->connected_at?->toIso8601ZuluString(),
            'disconnect_reason' => $connection?->disconnect_reason,
            'qr' => $this->resource->qr,
            'logged_out_on_phone' => $this->when($this->resource->loggedOutOnPhone !== null, $this->resource->loggedOutOnPhone),
        ];
    }
}
