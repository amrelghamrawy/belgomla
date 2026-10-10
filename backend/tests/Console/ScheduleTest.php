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
