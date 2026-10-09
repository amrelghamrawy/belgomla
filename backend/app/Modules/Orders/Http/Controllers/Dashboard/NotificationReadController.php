<?php

declare(strict_types=1);

namespace App\Modules\Orders\Http\Controllers\Dashboard;

use App\Modules\Orders\Actions\MarkNotificationsRead;
use App\Modules\Orders\Http\Requests\MarkNotificationsReadRequest;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

final readonly class NotificationReadController
{
    /** POST /api/dashboard/notifications/read — `{ ids: [] }` or `{ all: true }` (BR-N02) */
    public function store(MarkNotificationsReadRequest $request, MarkNotificationsRead $mark): JsonResponse
    {
        return ApiResponse::data(['marked' => $mark->handle($request->ids())]);
    }
}
