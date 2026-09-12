<?php

declare(strict_types=1);

namespace Hampel\BinaryLane\Api\Support;

use Hampel\BinaryLane\Api\Exception\InvalidArgumentException;

/**
 * Reading a value out of an API result without trusting it.
 *
 * Every one of these returns null - or an empty collection - rather than throwing when the
 * value is absent or the wrong shape. The specification is honest about its nullable fields
 * and there are a great many of them: a server that has never been backed up has a null
 * `attached_backup`, a server outside a VPC has a null `vpc_id`, and an action still running
 * has a null `completed_at`. A client that treated any of those as an error would break on
 * the ordinary case.
 *
 * It also absorbs the two changes an API in developer preview makes most often - a field
 * added after this package was written, and a field removed from an endpoint - neither of
 * which should stop a consumer reading the twenty fields that did not move.
 */
final class Cast
{
    public static function string(mixed $value): ?string
    {
        return is_scalar($value) ? (string) $value : null;
    }

    public static function int(mixed $value): ?int
    {
        return is_int($value) || (is_string($value) && $value !== '' && ctype_digit(ltrim($value, '-')))
            ? (int) $value
            : (is_float($value) ? (int) $value : null);
    }

    public static function float(mixed $value): ?float
    {
        return is_int($value) || is_float($value) || (is_string($value) && is_numeric($value))
            ? (float) $value
            : null;
    }

    public static function bool(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return match ($value) {
            1, '1' => true,
            0, '0' => false,
            default => null,
        };
    }

    /**
     * @return array<mixed>
     */
    public static function array(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    /**
     * The string-keyed part of a value that should have been an object.
     *
     * What an entity's fromArray() wants for a nested object - `region`, `size`, `image` on a
     * server - and it answers `[]` for the null those fields legitimately carry.
     *
     * @return array<string, mixed>
     */
    public static function object(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $object = [];

        foreach ($value as $key => $item) {
            if (is_string($key)) {
                $object[$key] = $item;
            }
        }

        return $object;
    }

    /**
     * A list of strings, with anything that is not one dropped.
     *
     * For `features`, `tags`, `nameservers` and the several string arrays on a size - none of
     * which is worth a class.
     *
     * @return list<string>
     */
    public static function strings(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $strings = [];

        foreach ($value as $item) {
            if (is_scalar($item)) {
                $strings[] = (string) $item;
            }
        }

        return $strings;
    }

    /**
     * A list of integers, for `backup_ids`, `server_ids` and the like.
     *
     * @return list<int>
     */
    public static function ints(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $ints = [];

        foreach ($value as $item) {
            $int = self::int($item);

            if ($int !== null) {
                $ints[] = $int;
            }
        }

        return $ints;
    }

    /**
     * A list of nested objects mapped into entities, for the arrays a response carries -
     * `servers`, `disks`, `networks.v4`, `forwarding_rules`.
     *
     * Anything in the array that is not an object is skipped rather than mapped, so one
     * malformed element does not take the other nineteen with it.
     *
     * @template T
     * @param  callable(array<string, mixed>): T  $map
     * @return list<T>
     */
    public static function objects(mixed $value, callable $map): array
    {
        if (!is_array($value)) {
            return [];
        }

        $mapped = [];

        foreach ($value as $item) {
            if (is_array($item)) {
                $mapped[] = $map(self::object($item));
            }
        }

        return $mapped;
    }

    /**
     * A nested object mapped into an entity, or null when the field was null or absent.
     *
     * @template T
     * @param  callable(array<string, mixed>): T  $map
     * @return T|null
     */
    public static function nested(mixed $value, callable $map): mixed
    {
        return is_array($value) && $value !== [] ? $map(self::object($value)) : null;
    }

    /**
     * A BinaryLane timestamp, as a DateTimeImmutable in UTC.
     *
     * THE TIMEZONE IS ASSUMED, NOT READ, WHEN THE STRING DOES NOT CARRY ONE. Every timestamp
     * in the specification is documented as "the timestamp in ISO8601 format" with
     * `format: date-time` and no example against the field - the one worked example anywhere
     * in the document is on the sample-set query parameters, `2022-12-30T22:50:00Z`, which
     * carries a `Z`. That is good evidence for the ordinary case and not a guarantee for every
     * field, so both are handled here:
     *
     *  - A value that DOES carry an offset or a `Z` is parsed as written and converted to
     *    UTC, which is the case the API is expected to produce.
     *  - A value that does NOT is read as UTC rather than as local time. That matters
     *    because `new DateTimeImmutable('2026-09-12T00:01:01')` interprets an unqualified
     *    string in PHP's OWN default timezone, so the same response read on a box set to
     *    Australia/Sydney and a box set to UTC would produce two instants ten or eleven hours
     *    apart depending on the season - silently, with a comparison against `now` quietly
     *    answering wrongly.
     *
     * Everything comes back in UTC either way, so a caller never has to ask which case it got.
     */
    public static function datetime(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        $utc = new \DateTimeZone('UTC');

        try {
            $parsed = new \DateTimeImmutable($value, $utc);
        } catch (\Exception) {
            return null;
        }

        return $parsed->setTimezone($utc);
    }

    /**
     * A timestamp on its way OUT, in the form the API's query parameters take.
     *
     * Converted to UTC first, so a caller passing a Sydney-local DateTime asks about the
     * instant it means rather than about a different hour. The `Z` form is written rather
     * than `+00:00` because it is the shorter of the two and both are valid ISO 8601.
     */
    public static function timestamp(\DateTimeInterface $value): string
    {
        return \DateTimeImmutable::createFromInterface($value)
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s\Z');
    }

    /**
     * A value that must be present, for a named constructor whose whole job is to refuse an
     * empty one.
     */
    public static function required(?string $value, string $message): string
    {
        if ($value === null || trim($value) === '') {
            throw new InvalidArgumentException($message);
        }

        return trim($value);
    }
}
