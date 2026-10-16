<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Services\WaGateway;

use App\Modules\Messaging\Enums\ConnectionStatus;
use App\Modules\Messaging\Exceptions\WaGatewayUnavailable;
use App\Modules\Messaging\Queries\StoreWhatsAppConnectionQuery;

/**
 * Laravel is the source of truth for which sessions wa-gateway runs (tasks/12 lesson 6). Every connected store
 * number that is not live is started again (its credentials survive a gateway restart); every session or
 * stored credential that is neither connected nor pairing here is logged out and deleted. A gateway that is
 * down fails the whole run; one session that cannot start is counted and the run goes on.
 */
final readonly class GatewayReconciler
{
    public function __construct(private WaGatewayClient $gateway, private StoreWhatsAppConnectionQuery $connections) {}

    /**
     * @return array{started: int, removed: int, failed: int}
     */
    public function run(): array
    {
        $sessions = $this->gateway->sessions();
        $statuses = $this->connections->sessionStatuses();
        $known = [...$sessions['live'], ...$sessions['stored']];
        $counts = ['started' => 0, 'removed' => 0, 'failed' => 0];

        foreach ($statuses as $key => $status) {
            if ($status === ConnectionStatus::Connected->value && ! in_array($key, $sessions['live'], true)) {
                $counts[$this->attempt(fn (): array => $this->gateway->start($key)) ? 'started' : 'failed']++;
            }
        }

        foreach (array_unique($known) as $key) {
            if (! in_array($statuses->get($key), [ConnectionStatus::Connected->value, ConnectionStatus::Pairing->value], true)) {
                $counts[$this->attempt(fn (): bool => $this->gateway->remove($key)) ? 'removed' : 'failed']++;
            }
        }

        return $counts;
    }

    /**
     * @param  callable(): mixed  $call
     */
    private function attempt(callable $call): bool
    {
        try {
            $call();

            return true;
        } catch (WaGatewayUnavailable) {
            return false;
        }
    }
}
