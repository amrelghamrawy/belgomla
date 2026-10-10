<?php

declare(strict_types=1);

use App\Modules\Messaging\Jobs\SendMessage;
use App\Modules\Messaging\Models\SmsPhoneHeartbeat;
use App\Modules\Platform\Services\HealthChecks;
use Illuminate\Support\Facades\DB;
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
