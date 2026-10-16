<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Jobs;

use App\Modules\Messaging\Services\WaGateway\GatewayReconciler;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Runs the wa-gateway reconcile off the webhook request, as soon as a restarted gateway says it is up (D70),
 * so linked store numbers do not wait for the next scheduled run.
 */
final class ReconcileWaGateway implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct()
    {
        $this->onQueue('messaging');
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 30];
    }

    public function handle(GatewayReconciler $reconciler): void
    {
        if (config()->boolean('messaging.wa_gateway.enabled')) {
            $reconciler->run();
        }
    }
}
