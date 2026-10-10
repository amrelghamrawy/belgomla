<?php

declare(strict_types=1);

namespace App\Modules\Platform\Services;

use App\Modules\Messaging\Queries\LastPhoneHeartbeatQuery;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/**
 * The in-app checks behind `platform:monitor` (tasks/11): messaging queue backlog, recently failed jobs,
 * free disk and the SMS phone heartbeat (D49). The per-host `/up` checks run outside PHP, in monitor.sh.
 */
final readonly class HealthChecks
{
    public function __construct(private LastPhoneHeartbeatQuery $heartbeat) {}

    /**
     * @return array<string, string> problem key => Arabic description for the operator
     */
    public function problems(): array
    {
        return array_filter([
            'queue_backlog' => $this->queueBacklog(),
            'failed_jobs' => $this->failedJobs(),
            'disk' => $this->disk(),
            'sms_heartbeat' => $this->smsHeartbeat(),
        ], fn (?string $problem): bool => $problem !== null);
    }

    private function queueBacklog(): ?string
    {
        $size = Queue::size('messaging');
        $max = config()->integer('monitor.queue_backlog_max');

        return $size > $max ? "طابور الرسائل متأخر: {$size} رسالة في الانتظار (الحد {$max})" : null;
    }

    private function failedJobs(): ?string
    {
        $count = DB::table('failed_jobs')->where('failed_at', '>=', now()->subHour())->count();

        return $count > 0 ? "{$count} مهمة فشلت في الساعة الأخيرة (php artisan queue:failed)" : null;
    }

    private function disk(): ?string
    {
        $path = config()->string('monitor.disk_path');
        $free = @disk_free_space($path);
        $total = @disk_total_space($path);

        if ($free === false || $total === false || $total <= 0) {
            return "تعذر قراءة المساحة الحرة على {$path}";
        }

        $percent = (int) floor($free / $total * 100);
        $min = config()->integer('monitor.disk_min_free_percent');

        return $percent < $min ? "المساحة الحرة على القرص {$percent}% فقط (الحد {$min}%)" : null;
    }

    private function smsHeartbeat(): ?string
    {
        if (config('messaging.driver') !== 'hypersender') {
            return null;
        }

        $last = $this->heartbeat->get();
        $max = config()->integer('monitor.heartbeat_max_minutes');

        return match (true) {
            ! $last instanceof CarbonImmutable => 'هاتف الرسائل النصية لم يرسل أي نبضة بعد',
            $last->lt(now()->subMinutes($max)) => 'هاتف الرسائل النصية بلا نبضة منذ '.$last->toDateTimeString().' UTC',
            default => null,
        };
    }
}
