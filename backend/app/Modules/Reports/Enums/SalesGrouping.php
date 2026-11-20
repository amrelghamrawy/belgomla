<?php

declare(strict_types=1);

namespace App\Modules\Reports\Enums;

use Carbon\CarbonImmutable;

/** The bucket size of the sales report (D5 a); weeks start on Saturday (D58). */
enum SalesGrouping: string
{
    case Day = 'day';
    case Week = 'week';
    case Month = 'month';

    public function label(): string
    {
        return match ($this) {
            self::Day => 'يومي',
            self::Week => 'أسبوعي',
            self::Month => 'شهري',
        };
    }

    /**
     * The SQL expression of the bucket a local timestamp falls in. Postgres truncates to ISO Monday weeks, so for
     * Saturday weeks (D58) the day is shifted by two before truncating and back after.
     */
    public function bucket(string $local): string
    {
        return match ($this) {
            self::Day => "date_trunc('day', {$local})",
            self::Week => "date_trunc('week', {$local} + interval '2 days') - interval '2 days'",
            self::Month => "date_trunc('month', {$local})",
        };
    }

    /**
     * The first day (`Y-m-d`) of every bucket that overlaps the range `from`..`to` (both `Y-m-d`, inclusive): a week
     * bucket starts on the Saturday on or before `from`, a month bucket on the first of its month.
     *
     * @return list<string>
     */
    public function periods(string $from, string $to): array
    {
        $day = CarbonImmutable::createFromFormat('!Y-m-d', $from, 'UTC');
        $last = CarbonImmutable::createFromFormat('!Y-m-d', $to, 'UTC');

        if (! $day instanceof CarbonImmutable || ! $last instanceof CarbonImmutable) {
            return [];
        }

        $period = match ($this) {
            self::Day => $day,
            self::Week => $day->subDays(($day->dayOfWeek + 1) % 7),
            self::Month => $day->startOfMonth(),
        };
        $periods = [];

        while ($period->lessThanOrEqualTo($last)) {
            $periods[] = $period->toDateString();
            $period = match ($this) {
                self::Day => $period->addDay(),
                self::Week => $period->addWeek(),
                self::Month => $period->addMonthNoOverflow(),
            };
        }

        return $periods;
    }

    /**
     * @return array<string, string>
     */
    public static function toArray(): array
    {
        $out = [];

        foreach (self::cases() as $case) {
            $out[$case->value] = $case->label();
        }

        return $out;
    }
}
