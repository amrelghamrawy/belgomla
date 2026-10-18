<?php

declare(strict_types=1);

use App\Modules\Messaging\Jobs\SendMessage;
use App\Modules\Messaging\Models\SmsPhoneHeartbeat;
use App\Modules\Messaging\Models\WhatsAppConnection;
use App\Modules\Platform\Services\HealthChecks;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function (): void {
    config(['monitor.disk_min_free_percent' => 0]); // independent of the real disk of the machine running the tests
});

function failedJobAt(DateTimeInterface $at): void
{
    DB::table('failed_jobs')->insert([
        'uuid' => (string) Str::uuid(),
        'connection' => 'redis',
        'queue' => 'messaging',
        'payload' => '{}',
        'exception' => 'RuntimeException',
        'failed_at' => $at,
    ]);
}

test('a healthy app has no problems', function (): void {
    expect(app(HealthChecks::class)->problems())->toBe([]);
});

test('a messaging queue above the backlog limit is a problem', function (): void {
    Queue::fake();
    config(['monitor.queue_backlog_max' => 2]);

    SendMessage::dispatch(1);
    SendMessage::dispatch(2);
    expect(app(HealthChecks::class)->problems())->not->toHaveKey('queue_backlog');

    SendMessage::dispatch(3);
    expect(app(HealthChecks::class)->problems())->toHaveKey('queue_backlog');
});

test('a job that failed in the last hour is a problem, an older one is not', function (): void {
    failedJobAt(now()->subHours(2));
    expect(app(HealthChecks::class)->problems())->not->toHaveKey('failed_jobs');

    failedJobAt(now()->subMinutes(10));
    expect(app(HealthChecks::class)->problems()['failed_jobs'] ?? null)->toContain('1 مهمة');
});

test('low free disk space is a problem', function (): void {
    config(['monitor.disk_min_free_percent' => 101]);

    expect(app(HealthChecks::class)->problems())->toHaveKey('disk');
});

test('an unreadable disk path is a problem', function (): void {
    config(['monitor.disk_path' => base_path('does-not-exist')]);

    expect(app(HealthChecks::class)->problems()['disk'] ?? null)->toContain('تعذر');
});

test('D49 the sms heartbeat is checked only with the hypersender driver', function (): void {
    config(['messaging.driver' => 'log']);

    expect(app(HealthChecks::class)->problems())->not->toHaveKey('sms_heartbeat');
});

test('D49 an sms phone that never sent a heartbeat is a problem', function (): void {
    config(['messaging.driver' => 'hypersender']);

    expect(app(HealthChecks::class)->problems()['sms_heartbeat'] ?? null)->toContain('لم يرسل');
});

test('D49 the sms phone is offline after 2 hours without a heartbeat', function (int $minutesAgo, bool $offline): void {
    config(['messaging.driver' => 'hypersender']);
    SmsPhoneHeartbeat::factory()->create(['last_seen_at' => now()->subMinutes($minutesAgo)]);

    expect(array_key_exists('sms_heartbeat', app(HealthChecks::class)->problems()))->toBe($offline);
})->with([
    'just now' => [1, false],
    'just under 2 hours' => [119, false],
    'over 2 hours' => [121, true],
]);

test('D49 the newest heartbeat of any phone counts', function (): void {
    config(['messaging.driver' => 'hypersender']);
    SmsPhoneHeartbeat::factory()->create(['last_seen_at' => now()->subHours(5)]);
    SmsPhoneHeartbeat::factory()->create(['last_seen_at' => now()->subMinutes(5)]);

    expect(app(HealthChecks::class)->problems())->not->toHaveKey('sms_heartbeat');
});

test('D70 wa-gateway is not checked while it is disabled', function (): void {
    config(['messaging.wa_gateway.enabled' => false]);

    expect(app(HealthChecks::class)->problems())->not->toHaveKey('wa_gateway');
});

test('D70 a wa-gateway that does not answer is a problem', function (): void {
    useWaGateway();
    Http::fake(['*' => Http::response(['error' => 'server_error'], 500)]);

    expect(app(HealthChecks::class)->problems()['wa_gateway'] ?? null)->toContain('لا تستجيب');
});

test('D70 fewer open sessions than connected store numbers is a problem', function (int $open, bool $problem): void {
    useWaGateway();
    $this->inTenant(fn () => WhatsAppConnection::factory()->connected('+201001111111')->create());
    $this->asTenant($this->otherTenant(), fn () => WhatsAppConnection::factory()->connected('+201002222222')->create());
    Http::fake(['127.0.0.1:3100/health' => Http::response(['ok' => true, 'sessions' => $open, 'open' => $open])]);

    expect(array_key_exists('wa_gateway', app(HealthChecks::class)->problems()))->toBe($problem);
})->with([
    'one of two open' => [1, true],
    'both open' => [2, false],
    'more open than connected (pairing)' => [3, false],
]);

test('D70 the wa-gateway problem names both counts', function (): void {
    useWaGateway();
    $this->inTenant(fn () => WhatsAppConnection::factory()->connected()->create());
    Http::fake(['127.0.0.1:3100/health' => Http::response(['ok' => true, 'sessions' => 0, 'open' => 0])]);

    expect(app(HealthChecks::class)->problems()['wa_gateway'] ?? null)->toBe('wa-gateway: 0 جلسة مفتوحة من 1 رقم متجر مربوط');
});
