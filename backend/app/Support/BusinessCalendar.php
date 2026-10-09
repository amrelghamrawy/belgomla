<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * D60: calendar days of the merchant's zone (`config('app.business_timezone')`, passed in by the caller) as UTC
 * instants, for `from`/`to` day filters and report buckets. A `Y-m-d` is validated before it gets here.
 */
final class BusinessCalendar
{
    /** The first instant of a local day, in UTC. */
    public static function dayStart(string $day, string $timezone): CarbonImmutable
    {
        return self::parse($day, $timezone)->startOfDay()->utc();
    }

    /** The last instant of a local day, in UTC. */
    public static function dayEnd(string $day, string $timezone): CarbonImmutable
    {
        return self::parse($day, $timezone)->endOfDay()->utc();
    }

    /** Today's local date as `Y-m-d`. */
    public static function today(string $timezone): string
    {
        return CarbonImmutable::now($timezone)->toDateString();
    }

    private static function parse(string $day, string $timezone): CarbonImmutable
    {
        $date = CarbonImmutable::createFromFormat('!Y-m-d', $day, $timezone);

        if (! $date instanceof CarbonImmutable) {
            throw new InvalidArgumentException("\"{$day}\" is not a Y-m-d day.");
        }

        return $date;
    }
}
