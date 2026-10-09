<?php

declare(strict_types=1);

namespace App\Modules\Audit\Http\Requests;

use App\Modules\Audit\Data\AuditLogFiltersData;
use App\Support\BusinessCalendar;
use App\Support\Http\PaginatedRequest;

/**
 * GET /api/dashboard/audit: `?entity_type=&entity_id=&actor_id=&from=&to=&batch_id=` (08-api-contract.md §12).
 * `entity_type` is a table name (`orders`, `collections`, `products`, …); `from`/`to` are business days (D60).
 */
final class ListAuditLogsRequest extends PaginatedRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'entity_type' => ['sometimes', 'string', 'max:40'],
            'entity_id' => ['sometimes', 'integer', 'min:1'],
            'actor_id' => ['sometimes', 'integer', 'min:1'],
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:from'],
            'batch_id' => ['sometimes', 'uuid'],
        ];
    }

    public function filters(): AuditLogFiltersData
    {
        $timezone = config()->string('app.business_timezone');

        return new AuditLogFiltersData(
            entityType: $this->filled('entity_type') ? $this->string('entity_type')->value() : null,
            entityId: $this->has('entity_id') ? $this->integer('entity_id') : null,
            actorId: $this->has('actor_id') ? $this->integer('actor_id') : null,
            from: $this->filled('from') ? BusinessCalendar::dayStart($this->string('from')->value(), $timezone) : null,
            to: $this->filled('to') ? BusinessCalendar::dayEnd($this->string('to')->value(), $timezone) : null,
            batchId: $this->filled('batch_id') ? mb_strtolower($this->string('batch_id')->value()) : null,
        );
    }
}
