<?php

namespace App\Modules\Audit\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Append-only. No updates or deletes are ever issued against this table (Tech §16).
 */
class AuditLog extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    protected $fillable = [
        'tenant_id', 'actor_type', 'actor_id', 'action', 'entity_type', 'entity_id',
        'old_values', 'new_values', 'batch_id', 'ip', 'user_agent',
    ];

    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'actor' => ['type' => $this->actor_type, 'id' => $this->actor_id],
            'action' => $this->action,
            'entity' => ['type' => $this->entity_type, 'id' => $this->entity_id],
            'old_values' => $this->old_values,
            'new_values' => $this->new_values,
            'batch_id' => $this->batch_id,
            'ip' => $this->ip,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
