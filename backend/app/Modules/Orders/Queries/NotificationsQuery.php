<?php

declare(strict_types=1);

namespace App\Modules\Orders\Queries;

use App\Modules\Orders\Models\MerchantNotification;
use App\Modules\Tenancy\Enums\Permission;
use App\Modules\Tenancy\Queries\CurrentPermissionsQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * GET /api/dashboard/notifications (BR-N02): the tenant's notifications newest first, only those the signed-in user's
 * role may see (D82: an accountant is not told about new orders).
 */
final readonly class NotificationsQuery
{
    public function __construct(private CurrentPermissionsQuery $permissions) {}

    /**
     * @return LengthAwarePaginator<int, MerchantNotification>
     */
    public function paginate(bool $unreadOnly, int $perPage): LengthAwarePaginator
    {
        return $this->visible()
            ->when($unreadOnly, fn (Builder $query): Builder => $query->whereNull('read_at'))
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    public function unreadCount(): int
    {
        return $this->visible()->whereNull('read_at')->count();
    }

    /**
     * The notifications the signed-in user may see. Without a dashboard user it is the system (a seeder or command),
     * which sees them all; every dashboard route has a signed-in user.
     *
     * @return Builder<MerchantNotification>
     */
    public function visible(): Builder
    {
        return MerchantNotification::query()->when(
            $this->permissions->role() instanceof \App\Modules\Tenancy\Enums\TenantUserRole,
            fn (Builder $query): Builder => $query->whereIn('permission', array_map(fn (Permission $permission): string => $permission->value, $this->permissions->get())),
        );
    }
}
