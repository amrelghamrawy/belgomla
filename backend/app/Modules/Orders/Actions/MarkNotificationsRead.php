<?php

declare(strict_types=1);

namespace App\Modules\Orders\Actions;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Orders\Models\MerchantNotification;
use App\Modules\Orders\Queries\NotificationsQuery;
use Illuminate\Support\Facades\DB;

/**
 * POST /api/dashboard/notifications/read (BR-N02): marks the given notifications of the tenant read, or all of
 * them when no ids are given. Ids of another tenant, ones the user's role may not see (D82) or already read ones are
 * skipped. Returns how many changed;
 * audits `notifications.read` when any did (BR-L).
 */
final readonly class MarkNotificationsRead
{
    public function __construct(private AuditLogger $audit, private NotificationsQuery $notifications) {}

    /**
     * @param  list<int>|null  $ids  null = all
     */
    public function handle(?array $ids): int
    {
        return DB::transaction(function () use ($ids): int {
            $query = $this->notifications->visible()->whereNull('read_at');

            if ($ids !== null) {
                $query->whereKey($ids);
            }

            $marked = $query->orderBy('id')->pluck('id')->all();

            if ($marked === []) {
                return 0;
            }

            MerchantNotification::query()->whereKey($marked)->update(['read_at' => now()]);
            $this->audit->log('notifications.read', 'notifications', null, ['ids' => $marked]);

            return count($marked);
        });
    }
}
