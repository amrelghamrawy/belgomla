<?php

declare(strict_types=1);

use App\Modules\Customers\Services\InvitationTokens;

test('BR-CU08 a token is 40 random alphanumeric characters', function (): void {
    $tokens = app(InvitationTokens::class);

    expect($tokens->generate())->toMatch('/^[A-Za-z0-9]{40}$/')
        ->and($tokens->generate())->not->toBe($tokens->generate());
});

test('BR-CU08 only the sha256 of a token is stored', function (): void {
    expect(app(InvitationTokens::class)->hash('abc'))->toBe(hash('sha256', 'abc'));
});

test('BR-CU08 the link points at the current tenant storefront', function (): void {
    $link = $this->inTenant(fn () => app(InvitationTokens::class)->link('tok123'));

    expect($link)->toBe('https://alnoor.gomal.test/invite/tok123');
});

test('the link format is configurable', function (): void {
    config(['customers.invitation.url' => 'http://{host}:3000/activate?t={token}']);

    expect($this->inTenant(fn () => app(InvitationTokens::class)->link('tok123')))->toBe('http://alnoor.gomal.test:3000/activate?t=tok123');
});
