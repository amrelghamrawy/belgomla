<?php

declare(strict_types=1);

namespace App\Modules\Audit\Data;

use App\Modules\Audit\Http\Requests\ListAuditLogsRequest;
use Carbon\CarbonImmutable;

/** The audit log filters (08-api-contract.md §12); `from`/`to` are already UTC bounds of business days (D60). */
final readonly class AuditLogFiltersData
{
    public function __construct(
        public ?string $entityType = null,
        public ?int $entityId = null,
        public ?int $actorId = null,
        public ?CarbonImmutable $from = null,
        public ?CarbonImmutable $to = null,
        public ?string $batchId = null,
    ) {}

    public static function fromRequest(ListAuditLogsRequest $request): self
    {
        return $request->filters();
    }
}
