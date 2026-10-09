<?php

declare(strict_types=1);

namespace App\Modules\Audit\Http\Controllers\Dashboard;

use App\Modules\Audit\Data\AuditLogFiltersData;
use App\Modules\Audit\Http\Requests\ListAuditLogsRequest;
use App\Modules\Audit\Http\Resources\AuditLogResource;
use App\Modules\Audit\Queries\AuditLogsQuery;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final readonly class AuditLogController
{
    /** GET /api/dashboard/audit?entity_type=&entity_id=&actor_id=&from=&to=&batch_id= — BR-L */
    public function index(ListAuditLogsRequest $request, AuditLogsQuery $logs): AnonymousResourceCollection
    {
        return AuditLogResource::collection($logs->paginate(AuditLogFiltersData::fromRequest($request), $request->perPage()));
    }
}
