<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Console;

use App\Modules\Messaging\Exceptions\WaGatewayUnavailable;
use App\Modules\Messaging\Services\WaGateway\GatewayReconciler;
use Illuminate\Console\Command;

/**
 * `messaging:wa-reconcile` (D70, tasks/12): makes wa-gateway run exactly the connected store numbers.
 * Scheduled every five minutes; also run by ReconcileWaGateway when the gateway restarts.
 */
final class WaReconcileCommand extends Command
{
    protected $signature = 'messaging:wa-reconcile';

    protected $description = 'Start connected store WhatsApp sessions on wa-gateway and remove stray ones';

    public function handle(GatewayReconciler $reconciler): int
    {
        if (! config()->boolean('messaging.wa_gateway.enabled')) {
            $this->info('wa-gateway is disabled.');

            return self::SUCCESS;
        }

        try {
            $counts = $reconciler->run();
        } catch (WaGatewayUnavailable) {
            $this->error('wa-gateway is not reachable.');

            return self::FAILURE;
        }

        $this->info("started {$counts['started']}, removed {$counts['removed']}, failed {$counts['failed']}");

        return $counts['failed'] === 0 ? self::SUCCESS : self::FAILURE;
    }
}
