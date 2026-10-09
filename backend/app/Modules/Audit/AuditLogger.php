<?php

declare(strict_types=1);

namespace App\Modules\Audit;

use App\Modules\Audit\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Single entry point for writing audit records (BR-L).
 *
 * Usage:
 *   $audit->log('settings.updated', $settings, $old, $new);
 *   $batch = $audit->newBatchId(); $audit->log(..., batchId: $batch);
 */
final readonly class AuditLogger
{
    public function __construct(private Request $request) {}

    /**
     * @param  array<string, mixed>|null  $oldValues
     * @param  array<string, mixed>|null  $newValues
     */
    public function log(
        string $action,
        Model|string $entity,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?string $batchId = null,
        ?int $entityId = null,
    ): AuditLog {
        [$actorType, $actorId] = $this->actor();

        if ($entity instanceof Model) {
            $entityType = $entity->getTable();
            $key = $entity->getKey();
            $entityId = is_int($key) ? $key : null;
        } else {
            $entityType = $entity;
        }

        return AuditLog::create([
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'old_values' => $oldValues === null ? null : $this->diffOnly($oldValues, $newValues),
            'new_values' => $newValues === null ? null : $this->diffOnly($newValues, $oldValues),
            'batch_id' => $batchId,
            'ip' => $this->request->ip(),
            'user_agent' => Str::limit((string) $this->request->userAgent(), 490, ''),
        ]);
    }

    public function newBatchId(): string
    {
        return (string) Str::uuid();
    }

    /**
     * Keep only keys whose value differs between the two arrays (both sides keep the same key set).
     *
     * @param  array<string, mixed>  $side
     * @param  array<string, mixed>|null  $other
     * @return array<string, mixed>
     */
    private function diffOnly(array $side, ?array $other): array
    {
        if ($other === null) {
            return $side;
        }

        return array_filter(
            $side,
            fn (mixed $value, string $key): bool => ! array_key_exists($key, $other) || $other[$key] !== $value,
            ARRAY_FILTER_USE_BOTH
        );
    }

    /**
     * The acting principal. Customers are added when the Customers module registers its guard.
     *
     * @return array{0: string, 1: int|null}
     */
    private function actor(): array
    {
        foreach (['tenant_user' => 'tenant_user', 'customer' => 'customer'] as $guard => $type) {
            if (! array_key_exists($guard, (array) config('auth.guards'))) {
                continue;
            }

            $user = $this->request->user($guard);

            if ($user !== null) {
                $id = $user->getAuthIdentifier();

                return [$type, is_int($id) ? $id : null];
            }
        }

        return ['system', null];
    }
}
