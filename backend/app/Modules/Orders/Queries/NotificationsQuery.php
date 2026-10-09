<?php

declare(strict_types=1);

namespace App\Modules\Orders\Queries;

use App\Modules\Orders\Models\MerchantNotification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

/** GET /api/dashboard/notifications (BR-N02): the tenant's notifications for its owners, newest first. */
final readonly class NotificationsQuery
{
    /**
     * @return LengthAwarePaginator<int, MerchantNotification>
     */
    public function paginate(bool $unreadOnly, int $perPage): LengthAwarePaginator
    {
        return MerchantNotification::query()
            ->when($unreadOnly, fn (Builder $query): Builder => $query->whereNull('read_at'))
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    public function unreadCount(): int
    {
        return MerchantNotification::query()->whereNull('read_at')->count();
    }
}
