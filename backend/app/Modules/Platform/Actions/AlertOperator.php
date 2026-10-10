<?php

declare(strict_types=1);

namespace App\Modules\Platform\Actions;

use App\Modules\Messaging\Data\MessagePayload;
use App\Modules\Messaging\Enums\MessagePurpose;
use App\Modules\Messaging\Services\Messenger;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Queries\TenantBySubdomainQuery;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Tells the platform operator what `platform:monitor` found (tasks/11, D65). A new problem is alerted at once,
 * an open one again every `monitor.realert_minutes`, and one message reports the problems that cleared.
 * Everything goes to the `monitor` log; WhatsApp goes through Messenger as the configured alert tenant, because
 * Messenger logs every message for a tenant. A failed send is logged and never stops the monitor.
 */
final readonly class AlertOperator
{
    private const string OPEN_PROBLEMS = 'monitor.open_problems';

    public function __construct(
        private Messenger $messenger,
        private TenantContext $context,
        private TenantBySubdomainQuery $tenants,
    ) {}

    /**
     * @param  array<string, string>  $problems  problem key => description
     * @return array{alerted: list<string>, recovered: list<string>}
     */
    public function handle(array $problems): array
    {
        $open = $this->openProblems();
        $now = now()->getTimestamp();
        $realertAfter = config()->integer('monitor.realert_minutes') * 60;

        $due = array_filter($problems, fn (string $key): bool => ! isset($open[$key]) || $now - $open[$key] >= $realertAfter, ARRAY_FILTER_USE_KEY);
        $recovered = array_values(array_diff(array_keys($open), array_keys($problems)));

        foreach (array_keys($due) as $key) {
            $open[$key] = $now;
        }

        Cache::forever(self::OPEN_PROBLEMS, array_diff_key($open, array_flip($recovered)));

        if ($due !== []) {
            $lines = array_map(fn (string $key, string $description): string => "{$key}: {$description}", array_keys($due), $due);
            $this->notify("⚠️ بالجملة: مشكلة في الإنتاج\n- ".implode("\n- ", $lines));
        }

        if ($recovered !== []) {
            $this->notify("✅ بالجملة: عادت للعمل\n- ".implode("\n- ", $recovered));
        }

        return ['alerted' => array_keys($due), 'recovered' => $recovered];
    }

    /**
     * @return array<string, int> problem key => when it was last alerted
     */
    private function openProblems(): array
    {
        $cached = Cache::get(self::OPEN_PROBLEMS);
        $open = [];

        foreach (is_array($cached) ? $cached : [] as $key => $alertedAt) {
            if (is_string($key) && is_int($alertedAt)) {
                $open[$key] = $alertedAt;
            }
        }

        return $open;
    }

    private function notify(string $text): void
    {
        Log::channel('monitor')->warning($text);

        $subdomain = config('monitor.alert_tenant');
        $phone = config('monitor.alert_phone');
        $tenant = is_string($subdomain) && $subdomain !== '' ? $this->tenants->find($subdomain) : null;

        if (! $tenant instanceof Tenant || ! is_string($phone) || $phone === '') {
            Log::channel('monitor')->warning('Operator alert not sent: MONITOR_ALERT_TENANT / MONITOR_ALERT_PHONE missing or unknown.');

            return;
        }

        try {
            $this->context->runAs($tenant, fn (): mixed => $this->messenger->send(new MessagePayload($phone, MessagePurpose::SystemAlert, $text)));
        } catch (Throwable $e) {
            Log::channel('monitor')->error('Operator alert failed: '.$e->getMessage());
        }
    }
}
