<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;

test('failed jobs older than 30 days are pruned daily', function (): void {
    $events = collect(app(Schedule::class)->events());

    $prune = $events->first(fn (Event $event): bool => str_contains((string) $event->command, 'queue:prune-failed --hours=720'));

    expect($prune)->not->toBeNull()
        ->and($prune?->expression)->toBe('0 0 * * *');
});

test('BR-INV07 D81 unconfirmed orders are expired every fifteen minutes without overlapping runs', function (): void {
    $events = collect(app(Schedule::class)->events());

    $expire = $events->first(fn (Event $event): bool => str_contains((string) $event->command, 'orders:expire-pending'));

    expect($expire)->not->toBeNull()
        ->and($expire?->expression)->toBe('*/15 * * * *')
        ->and($expire?->withoutOverlapping)->toBeTrue();
});

test('D70 wa-gateway is reconciled every five minutes without overlapping runs', function (): void {
    $events = collect(app(Schedule::class)->events());

    $reconcile = $events->first(fn (Event $event): bool => str_contains((string) $event->command, 'messaging:wa-reconcile'));

    expect($reconcile)->not->toBeNull()
        ->and($reconcile?->expression)->toBe('*/5 * * * *')
        ->and($reconcile?->withoutOverlapping)->toBeTrue();
});
