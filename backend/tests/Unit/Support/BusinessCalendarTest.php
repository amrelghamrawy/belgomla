<?php

declare(strict_types=1);

use App\Support\BusinessCalendar;
use Carbon\CarbonImmutable;

test('D60 a cairo business day starts and ends at its local midnights, given in utc', function (): void {
    expect(BusinessCalendar::dayStart('2026-10-10', 'Africa/Cairo')->toIso8601String())->toBe('2026-10-09T21:00:00+00:00')
        ->and(BusinessCalendar::dayEnd('2026-10-10', 'Africa/Cairo')->toIso8601String())->toBe('2026-10-10T20:59:59+00:00');
});

test('D60 the day bounds follow the winter offset', function (): void {
    expect(BusinessCalendar::dayStart('2026-01-15', 'Africa/Cairo')->toIso8601String())->toBe('2026-01-14T22:00:00+00:00');
});

test('D60 today is the local date, which runs ahead of utc late in the evening', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-10 22:30:00', 'UTC'));

    expect(BusinessCalendar::today('Africa/Cairo'))->toBe('2026-10-11')
        ->and(BusinessCalendar::today('UTC'))->toBe('2026-10-10');
});

test('a day that is not Y-m-d is rejected', function (): void {
    BusinessCalendar::dayStart('10/10/2026', 'Africa/Cairo');
})->throws(InvalidArgumentException::class);
