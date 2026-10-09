<?php

declare(strict_types=1);

namespace App\Modules\Audit\Models;

use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Append-only. No updates or deletes are ever issued against this table (Tech §16).
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $actor_type
 * @property int|null $actor_id
 * @property string $action
 * @property string $entity_type
 * @property int|null $entity_id
 * @property array<string, mixed>|null $old_values
 * @property array<string, mixed>|null $new_values
 * @property string|null $batch_id
 * @property string|null $ip
 * @property string|null $user_agent
 * @property Carbon $created_at
 */
class AuditLog extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    protected $fillable = [
        'tenant_id', 'actor_type', 'actor_id', 'action', 'entity_type', 'entity_id',
        'old_values', 'new_values', 'batch_id', 'ip', 'user_agent',
    ];

    /**
     * @return array<string, mixed>
     */
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
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
