<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api\Enum;

/**
 * The bucket size performance samples are aggregated into.
 *
 * THE INTERVAL DECIDES HOW FAR BACK THE DATA GOES, not just how coarse it is - a
 * five-minute series does not extend as far as a monthly one. Ask for the coarsest interval
 * that answers the question.
 */
enum DataInterval: string
{
    case FiveMinute = 'five-minute';
    case HalfHour = 'half-hour';
    case FourHour = 'four-hour';
    case Day = 'day';
    case Week = 'week';
    case Month = 'month';

    /**
     * How long one bucket covers, in seconds.
     *
     * A MONTH IS NOT A FIXED NUMBER OF SECONDS, so the value here is 30 days - useful for
     * sizing a chart, wrong for arithmetic on a calendar. Use the sample's own period for
     * that, which the API gives as real timestamps.
     */
    public function seconds(): int
    {
        return match ($this) {
            self::FiveMinute => 300,
            self::HalfHour => 1800,
            self::FourHour => 14400,
            self::Day => 86400,
            self::Week => 604800,
            self::Month => 2592000,
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::FiveMinute => '5 Minutes',
            self::HalfHour => '30 Minutes',
            self::FourHour => '4 Hours',
            self::Day => '1 Day',
            self::Week => '7 Days',
            self::Month => '1 Month',
        };
    }
}
