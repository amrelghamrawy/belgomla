<?php

declare(strict_types=1);

namespace App\Modules\Orders\Http\Resources;

use App\Modules\Orders\Models\MerchantNotification;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A dashboard notification (BR-N02): `details` carries the order number, customer name and total, plus the
 * reason of a customer cancellation.
 *
 * @property-read MerchantNotification $resource
 */
final class NotificationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $notification = $this->resource;

        return [
            'id' => $notification->id,
            'type' => $notification->type->value,
            'type_label' => $notification->type->label(),
            'details' => $notification->data, // not `data`: a `data` key would stop the response from being wrapped
            'read_at' => $notification->read_at?->toIso8601String(),
            'created_at' => $notification->created_at->toIso8601String(),
        ];
    }
}
