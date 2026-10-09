<?php

declare(strict_types=1);

use App\Modules\Tenancy\Http\Middleware\ResolveTenant;

test('subdomain parsing', function (string $host, ?string $expected): void {
    config(['tenancy.base_domain' => 'gomal.test']);

    expect(ResolveTenant::subdomainFromHost($host))->toBe($expected);
})->with([
    'simple' => ['alnoor.gomal.test', 'alnoor'],
    'upper case is normalised' => ['AlNoor.Gomal.TEST', 'alnoor'],
    'with digits and hyphen' => ['al-noor2.gomal.test', 'al-noor2'],
    'bare base domain' => ['gomal.test', null],
    'nested' => ['a.b.gomal.test', null],
    'reserved www' => ['www.gomal.test', null],
    'reserved api' => ['api.gomal.test', null],
    'reserved hooks' => ['hooks.gomal.test', null],
    'other domain' => ['alnoor.example.com', null],
    'suffix lookalike' => ['alnoorgomal.test', null],
    'empty' => ['', null],
]);

test('the middleware sets the context for a known tenant and 404s otherwise', function (): void {
    Illuminate\Support\Facades\Route::get('/api/_resolve', fn (): array => ['tenant' => app(App\Modules\Tenancy\TenantContext::class)->id()])
        ->middleware(ResolveTenant::class);

    $this->hostJson('alnoor.gomal.test', 'GET', '/api/_resolve')->assertOk()->assertJsonPath('tenant', $this->tenant->id);
    $this->hostJson('ghost.gomal.test', 'GET', '/api/_resolve')->assertNotFound();
});
