<?php

declare(strict_types=1);

namespace App\Modules\Platform\Console;

use App\Modules\Platform\Actions\AlertOperator;
use App\Modules\Platform\Services\HealthChecks;
use Illuminate\Console\Command;

/**
 * Runs the production health checks and alerts the operator (tasks/11). deploy/monitor.sh calls it every
 * 5 minutes and passes the checks it ran outside PHP (`/up` per tenant host) as `--report=key=description`.
 * Exits 1 while any problem is open, so the systemd unit shows the failure too.
 */
final class MonitorCommand extends Command
{
    protected $signature = 'platform:monitor
        {--report=* : An external failure as key=description, e.g. up:alnoor=/up returned 502}';

    protected $description = 'Check queue, failed jobs, disk and the SMS phone heartbeat; alert the operator';

    public function handle(HealthChecks $checks, AlertOperator $alert): int
    {
        $problems = [...$checks->problems(), ...$this->reports()];
        $result = $alert->handle($problems);

        foreach ($problems as $key => $description) {
            $this->warn("{$key}: {$description}");
        }

        $this->line(sprintf('Problems: %d, alerted: %d, recovered: %d', count($problems), count($result['alerted']), count($result['recovered'])));

        return $problems === [] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return array<string, string>
     */
    private function reports(): array
    {
        $reports = [];
        $option = $this->option('report');

        foreach (is_array($option) ? $option : [] as $report) {
            if (! is_string($report) || ! str_contains($report, '=')) {
                continue;
            }

            [$key, $description] = explode('=', $report, 2);
            $reports[mb_trim($key)] = mb_trim($description);
        }

        return $reports;
    }
}
