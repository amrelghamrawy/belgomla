<?php

declare(strict_types=1);

use App\Modules\Messaging\Models\OutboundMessage;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Queue;

test('D62 the newest otp sent to a phone is readable on the test route', function (): void {
    Queue::fake();
    $this->tenantJson('POST', '/api/store/auth/register', ['name' => 'محمود حسن', 'phone' => '01012345678', 'password' => 'secret123'])->assertOk();

    $response = $this->tenantJson('GET', '/api/_test/last-otp?phone=010%201234%205678');

    $response->assertOk()->assertExactJson(['data' => ['code' => lastOtpCode()]]);
});

test('D62 the phone is required', function (): void {
    $this->tenantJson('GET', '/api/_test/last-otp')
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonStructure(['error' => ['details' => ['phone']]]);
});

test('D62 a phone without an otp is not found', function (): void {
    $this->tenantJson('GET', '/api/_test/last-otp?phone=01012345678')
        ->assertNotFound()
        ->assertJsonPath('error.code', 'not_found');
});

test('D62 an otp sent on another tenant is not found here', function (): void {
    $this->asTenant($this->otherTenant(), fn () => OutboundMessage::factory()->create(['to_phone' => '+201012345678']));

    $this->tenantJson('GET', '/api/_test/last-otp?phone=01012345678')->assertNotFound();
});

test('D62 the test routes are not registered outside the local and testing environments', function (): void {
    app()->detectEnvironment(fn (): string => 'production');
    $router = app('router');
    $router->setRoutes(new RouteCollection);

    require base_path('routes/api.php');
    $router->getRoutes()->refreshNameLookups();

    expect($router->has('testing.last-otp'))->toBeFalse()
        ->and($router->has('store.tenant.show'))->toBeTrue();
});
