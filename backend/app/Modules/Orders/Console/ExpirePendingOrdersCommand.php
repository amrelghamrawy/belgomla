<?php

declare(strict_types=1);

namespace App\Modules\Orders\Console;

use App\Modules\Orders\Actions\ExpirePendingOrder;
use App\Modules\Orders\Queries\ExpirableOrdersQuery;
use App\Modules\Tenancy\Queries\PendingExpiryTenantsQuery;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Throwable;

/**
 * `orders:expire-pending` (BR-INV07, D81): cancels the unconfirmed orders that outlived their store's
 * `pending_expiry_hours`, store by store inside its tenant context, one order per transaction, so a failing order
 * is reported and the rest still run. Scheduled every fifteen minutes.
 */
final class ExpirePendingOrdersCommand extends Command
{
    protected $signature = 'orders:expire-pending';

    protected $description = 'Cancel pending orders that were not confirmed within their store\'s expiry hours';

    public function handle(PendingExpiryTenantsQuery $tenants, ExpirableOrdersQuery $orders, ExpirePendingOrder $expire, TenantContext $context): int
    {
        $counts = ['expired' => 0, 'skipped' => 0, 'failed' => 0];

        foreach ($tenants->get() as $tenant) {
            $context->runAs($tenant, function () use ($orders, $expire, &$counts): void {
                foreach ($orders->get() as $order) {
                    try {
                        $expire->handle($order) ? $counts['expired']++ : $counts['skipped']++;
                    } catch (Throwable $e) {
                        report($e);
                        $counts['failed']++;
                    }
                }
            });
        }

        $this->info("expired {$counts['expired']}, skipped {$counts['skipped']}, failed {$counts['failed']}");

        return $counts['failed'] === 0 ? self::SUCCESS : self::FAILURE;
    }
}
