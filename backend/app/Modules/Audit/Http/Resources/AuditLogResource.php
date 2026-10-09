<?php

declare(strict_types=1);

namespace App\Modules\Audit\Http\Resources;

use App\Modules\Audit\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read AuditLog $resource
 */
final class AuditLogResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'actor' => ['type' => $this->resource->actor_type->value, 'id' => $this->resource->actor_id],
            'action' => $this->resource->action,
            'entity' => ['type' => $this->resource->entity_type, 'id' => $this->resource->entity_id],
            'old_values' => $this->resource->old_values,
            'new_values' => $this->resource->new_values,
            'batch_id' => $this->resource->batch_id,
            'ip' => $this->resource->ip,
            'created_at' => $this->resource->created_at->toIso8601String(),
        ];
    }
}
