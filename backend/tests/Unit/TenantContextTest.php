<?php

declare(strict_types=1);

use App\Modules\Tenancy\TenantContext;

test('get throws when no tenant is set', function (): void {
    (new TenantContext)->get();
})->throws(RuntimeException::class, 'No tenant is set');

test('set, has, id, clear', function (): void {
    $context = new TenantContext;

    expect($context->has())->toBeFalse()
        ->and($context->idOrNull())->toBeNull();

    $context->set($this->tenant);

    expect($context->has())->toBeTrue()
        ->and($context->id())->toBe($this->tenant->id)
        ->and($context->get()->is($this->tenant))->toBeTrue();

    $context->clear();

    expect($context->has())->toBeFalse();
});

test('runAs restores the previous tenant even when the callback throws', function (): void {
    $context = new TenantContext;
    $context->set($this->tenant);
    $other = $this->otherTenant();

    $result = $context->runAs($other, fn (): int => $context->id());

    expect($result)->toBe($other->id)
        ->and($context->id())->toBe($this->tenant->id);

    expect(fn (): mixed => $context->runAs($other, fn () => throw new LogicException('x')))->toThrow(LogicException::class);

    expect($context->id())->toBe($this->tenant->id);
});
