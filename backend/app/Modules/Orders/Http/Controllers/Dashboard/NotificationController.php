<?php

declare(strict_types=1);

namespace App\Modules\Orders\Http\Controllers\Dashboard;

use App\Modules\Orders\Http\Requests\ListNotificationsRequest;
use App\Modules\Orders\Http\Resources\NotificationResource;
use App\Modules\Orders\Queries\NotificationsQuery;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final readonly class NotificationController
{
    /** GET /api/dashboard/notifications — BR-N02; `unread_count` next to the page for the bell badge. */
    public function index(ListNotificationsRequest $request, NotificationsQuery $notifications): AnonymousResourceCollection
    {
        return NotificationResource::collection($notifications->paginate($request->unreadOnly(), $request->perPage()))
            ->additional(['unread_count' => $notifications->unreadCount()]);
    }
}
