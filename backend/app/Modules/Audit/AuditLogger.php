<?php

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
class AuditLogger
{
    public function __construct(private readonly Request $request) {}

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
            $entityId = $entity->getKey();
        } else {
            $entityType = $entity;
        }

        return AuditLog::create([
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'old_values' => $oldValues ? $this->diffOnly($oldValues, $newValues) : null,
            'new_values' => $newValues ? $this->diffOnly($newValues, $oldValues) : null,
            'batch_id' => $batchId,
            'ip' => $this->request->ip(),
            'user_agent' => Str::limit((string) $this->request->userAgent(), 490, ''),
        ]);
    }

    public function newBatchId(): string
    {
        return (string) Str::uuid();
    }

    /** Keep only keys whose value differs between the two arrays (both sides keep the same key set). */
    private function diffOnly(array $side, ?array $other): array
    {
        if ($other === null) {
            return $side;
        }

        return array_filter(
            $side,
            fn ($value, $key) => ! array_key_exists($key, $other) || $other[$key] !== $value,
            ARRAY_FILTER_USE_BOTH
        );
    }

    /** @return array{0:string,1:?int} */
    private function actor(): array
    {
        if ($user = $this->request->user('tenant_user')) {
            return ['tenant_user', $user->id];
        }
        if ($customer = $this->request->user('customer')) {
            return ['customer', $customer->id];
        }

        return ['system', null];
    }
}
