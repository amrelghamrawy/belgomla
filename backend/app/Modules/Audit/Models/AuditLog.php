<?php

declare(strict_types=1);

namespace App\Modules\Audit\Models;

use App\Modules\Audit\Enums\ActorType;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Append-only. No updates or deletes are ever issued against this table (Tech §16).
 *
 * @property int $id
 * @property int $tenant_id
 * @property ActorType $actor_type
 * @property int|null $actor_id
 * @property string $action
 * @property string $entity_type
 * @property int|null $entity_id
 * @property array<string, mixed>|null $old_values
 * @property array<string, mixed>|null $new_values
 * @property string|null $batch_id
 * @property string|null $ip
 * @property string|null $user_agent
 * @property CarbonImmutable $created_at
 */
final class AuditLog extends Model
{
    use BelongsToTenant;

    public const null UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'actor_type' => ActorType::class,
            'old_values' => 'array',
            'new_values' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
