<?php

declare(strict_types=1);

use App\Modules\Messaging\Enums\MessagePurpose;
use App\Modules\Messaging\Models\OutboundMessage;
use App\Modules\Platform\Actions\AlertOperator;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Support\Facades\Log;

/*
| platform:monitor + AlertOperator (tasks/11, D65): problems are alerted once, re-alerted after
| monitor.realert_minutes, recoveries reported once; WhatsApp goes through Messenger as the alert tenant.
*/

beforeEach(function (): void {
    app(TenantContext::class)->clear();

    $this->monitorLog = storage_path('logs/monitor-test-'.getmypid().'.log');
    @unlink($this->monitorLog);
    config(['logging.channels.monitor' => ['driver' => 'single', 'path' => $this->monitorLog, 'level' => 'info']]);
    Log::forgetChannel('monitor');

    config(['monitor.alert_tenant' => 'alnoor', 'monitor.alert_phone' => '01012345678', 'monitor.realert_minutes' => 60, 'monitor.disk_min_free_percent' => 0]);
});

afterEach(function (): void {
    @unlink($this->monitorLog);
});

/** @return list<OutboundMessage> */
function systemAlerts(): array
{
    return array_values(test()->inTenant(fn () => OutboundMessage::query()
        ->where('purpose', MessagePurpose::SystemAlert)
        ->orderBy('id')
        ->get()
        ->all()));
}

function monitorLog(): string
{
    return (string) @file_get_contents(test()->monitorLog);
}

test('a healthy run succeeds and sends nothing', function (): void {
    $this->artisan('platform:monitor')
        ->expectsOutputToContain('Problems: 0, alerted: 0, recovered: 0')
        ->assertSuccessful();

    expect(systemAlerts())->toBe([]);
});

test('D65 a reported problem is sent to the operator over whatsapp as the alert tenant and logged', function (): void {
    $this->artisan('platform:monitor', ['--report' => ['up:alnoor=/up returned 502']])
        ->expectsOutputToContain('up:alnoor: /up returned 502')
        ->assertFailed();

    $alerts = systemAlerts();

    expect($alerts)->toHaveCount(1)
        ->and($alerts[0]->tenant_id)->toBe($this->tenant->id)
        ->and($alerts[0]->to_phone)->toBe('+201012345678')
        ->and(monitorLog())->toContain('/up returned 502');
});

test('an open problem is not re-sent before the re-alert interval', function (): void {
    $this->artisan('platform:monitor', ['--report' => ['up:alnoor=down']])->assertFailed();
    $this->travel(59)->minutes();
    $this->artisan('platform:monitor', ['--report' => ['up:alnoor=down']])
        ->expectsOutputToContain('alerted: 0')
        ->assertFailed();

    expect(systemAlerts())->toHaveCount(1);

    $this->travel(2)->minutes();
    $this->artisan('platform:monitor', ['--report' => ['up:alnoor=down']])->expectsOutputToContain('alerted: 1');

    expect(systemAlerts())->toHaveCount(2);
});

test('a cleared problem is reported once as recovered', function (): void {
    $this->artisan('platform:monitor', ['--report' => ['up:alnoor=down']])->assertFailed();

    $this->artisan('platform:monitor')->expectsOutputToContain('recovered: 1')->assertSuccessful();
    $this->artisan('platform:monitor')->expectsOutputToContain('recovered: 0')->assertSuccessful();

    expect(systemAlerts())->toHaveCount(2)
        ->and(monitorLog())->toContain('up:alnoor');
});

test('a new problem next to an open one alerts only the new one', function (): void {
    expect(app(AlertOperator::class)->handle(['a' => 'first']))->toBe(['alerted' => ['a'], 'recovered' => []])
        ->and(app(AlertOperator::class)->handle(['a' => 'first', 'b' => 'second']))->toBe(['alerted' => ['b'], 'recovered' => []])
        ->and(app(AlertOperator::class)->handle(['b' => 'second']))->toBe(['alerted' => [], 'recovered' => ['a']]);
});

test('without an alert tenant or phone the alert is only logged', function (array $config): void {
    config($config);

    $this->artisan('platform:monitor', ['--report' => ['disk=full']])->assertFailed();

    expect(systemAlerts())->toBe([])
        ->and(monitorLog())->toContain('disk')
        ->and(monitorLog())->toContain('Operator alert not sent');
})->with([
    'no tenant' => [['monitor.alert_tenant' => null]],
    'unknown tenant' => [['monitor.alert_tenant' => 'nobody']],
    'no phone' => [['monitor.alert_phone' => '']],
]);

test('a failed whatsapp send is logged and does not stop the monitor', function (): void {
    config(['monitor.alert_phone' => 'not-a-phone']);

    $this->artisan('platform:monitor', ['--report' => ['disk=full']])->assertFailed();

    expect(systemAlerts())->toBe([])
        ->and(monitorLog())->toContain('Operator alert failed');
});

test('malformed reports are ignored', function (): void {
    $this->artisan('platform:monitor', ['--report' => ['no-equals-sign']])
        ->expectsOutputToContain('Problems: 0')
        ->assertSuccessful();
});

test('the alert leaves no tenant in context', function (): void {
    $this->artisan('platform:monitor', ['--report' => ['disk=full']])->assertFailed();

    expect(app(TenantContext::class)->has())->toBeFalse();
});
