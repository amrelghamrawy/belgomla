<?php

declare(strict_types=1);

use App\Modules\Tenancy\Http\Middleware\ResolveTenant;
use App\Support\Http\Middleware\IdempotencyKey;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;

beforeEach(function (): void {
    $this->runs = 0;
    $this->status = 201;

    Route::post('/api/_idem', function () {
        $this->runs++;

        return response()->json(['data' => ['run' => $this->runs]], $this->status);
    })->middleware([ResolveTenant::class, 'auth:customer', IdempotencyKey::class])->name('test.idempotent');

    $this->customer = $this->actingAsCustomer();
    $this->send = fn (string $key = 'k-1', array $body = ['a' => 1]) => $this->tenantJson('POST', '/api/_idem', $body, headers: ['Idempotency-Key' => $key]);
});

test('without the header every request runs', function (): void {
    $this->tenantJson('POST', '/api/_idem')->assertCreated();
    $this->tenantJson('POST', '/api/_idem')->assertCreated()->assertJsonPath('data.run', 2);
});

test('D55 a retry with the same key replays the first answer without running again', function (): void {
    ($this->send)()->assertCreated()->assertJsonPath('data.run', 1)->assertHeaderMissing('Idempotent-Replayed');

    ($this->send)()
        ->assertCreated()
        ->assertExactJson(['data' => ['run' => 1]])
        ->assertHeader('Idempotent-Replayed', 'true');

    expect($this->runs)->toBe(1);
});

test('D55 another key runs again', function (): void {
    ($this->send)('k-1');

    ($this->send)('k-2')->assertJsonPath('data.run', 2);
});

test('D55 the same key with another body is refused', function (): void {
    ($this->send)('k-1', ['a' => 1]);

    ($this->send)('k-1', ['a' => 2])->assertUnprocessable()->assertJsonPath('error.code', 'idempotency_key_reused');

    expect($this->runs)->toBe(1);
});

test('D55 a failed answer is not stored, so a retry runs again', function (): void {
    $this->status = 422;
    ($this->send)()->assertUnprocessable();
    $this->status = 201;

    ($this->send)()->assertCreated()->assertJsonPath('data.run', 2)->assertHeaderMissing('Idempotent-Replayed');
});

test('D55 a retry while the first request still runs is refused', function (): void {
    /** @var LockProvider $store */
    $store = Cache::store()->getStore();
    $scope = 'idempotency:'.hash('sha256', implode('|', ['alnoor.gomal.test', 'customer:'.$this->customer->id, 'test.idempotent', 'k-1']));
    $store->lock($scope.':lock', 30)->get();

    ($this->send)()->assertConflict()->assertJsonPath('error.code', 'idempotency_in_progress');

    expect($this->runs)->toBe(0);
});

test('D55 keys are scoped to the user: another customer\'s request runs', function (): void {
    ($this->send)();
    $this->actingAsCustomer();

    ($this->send)()->assertJsonPath('data.run', 2);
});

test('D55 keys are scoped to the tenant host', function (): void {
    ($this->send)();
    $other = $this->otherTenant();
    $this->actingAsCustomer($this->makeCustomer(tenant: $other));

    $this->tenantJson('POST', '/api/_idem', ['a' => 1], $other, ['Idempotency-Key' => 'k-1'])->assertJsonPath('data.run', 2);
});

test('a malformed key is refused', function (string $key): void {
    ($this->send)($key)->assertUnprocessable()->assertJsonPath('error.code', 'invalid_idempotency_key');

    expect($this->runs)->toBe(0);
})->with(['spaces' => ['a key'], 'too long' => [str_repeat('k', 256)], 'arabic' => ['مفتاح']]);

test('D55 the replay lasts 24 hours', function (): void {
    ($this->send)();
    $this->travel(86_401)->seconds();

    ($this->send)()->assertJsonPath('data.run', 2);
});
