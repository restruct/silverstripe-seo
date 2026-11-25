<?php

namespace Restruct\Silverstripe\SEO\Schema;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use SilverStripe\ORM\FieldType\DBDate;
use SilverStripe\ORM\FieldType\DBDatetime;
use SilverStripe\ORM\FieldType\DBField;
use Throwable;

/**
 * Dates and times for structured data: ISO 8601, with the offset of the site's timezone.
 *
 * Silverstripe stores Date, Time and Datetime values without a timezone; they are local time of the site
 * (DBDatetime reads them with PHP's default timezone). Structured data needs the offset, and the offset depends on
 * the date itself (daylight saving time), so it is worked out per value rather than once.
 */
class SchemaDates
{
    /**
     * A date and a separate time (an event's StartDate and StartTime fields) as one ISO 8601 value:
     * `2027-07-14T19:30:00+02:00`. Without a time, just the date (`2027-07-14`); without a date, null.
     *
     * @param mixed $date DBDate, a `Y-m-d` string or a DateTimeInterface
     * @param mixed $time DBTime, an `H:i[:s]` string, a DateTimeInterface or null
     * @param string|null $timezone A timezone name; empty for PHP's default timezone
     */
    public static function combine(mixed $date, mixed $time, ?string $timezone = null): ?string
    {
        $day = static::scalar($date, 'Y-m-d');
        if ($day === '' || !static::isDate($day)) {
            return null;
        }
        $clock = static::scalar($time, 'H:i:s');
        if ($clock === '') {
            return $day;
        }
        try {
            $value = new DateTimeImmutable($day . ' ' . $clock, static::timezone($timezone));
        } catch (Throwable) {
            # A time that does not parse: the date alone is still correct
            return $day;
        }
        return $value->format(DateTimeInterface::ATOM);
    }

    /**
     * One date or date-time value as ISO 8601: a Date stays a date (`2027-07-01`), a string or DateTimeInterface
     * with an offset of its own keeps it. Null for an empty or unreadable value.
     *
     * The two kinds of value without an offset are read differently:
     * - a DBDatetime (a Datetime column, LastEdited, ...) holds PHP's default timezone, because that is what
     *   Silverstripe writes (DBDatetime::now(), DatetimeField); it is read in that zone and then expressed in the
     *   site timezone, so the moment stays the same;
     * - a plain string (from provideSchemaData, an API) is taken as local time of the site timezone, as a Date and
     *   Time pair is in combine().
     */
    public static function format(mixed $value, ?string $timezone = null): ?string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format(DateTimeInterface::ATOM);
        }
        # DBDatetime extends DBDate, so it is checked first
        $dateOnly = $value instanceof DBDate && !$value instanceof DBDatetime;
        $serverTime = $value instanceof DBDatetime;
        if ($value instanceof DBField) {
            $value = $value->getValue();
        }
        if (!is_string($value) || trim($value) === '') {
            return null;
        }
        $value = trim($value);

        if (static::isDate($value)) {
            return $value;
        }
        if ($dateOnly) {
            # A Date field holding something else than a date: nothing sensible to give
            return null;
        }
        try {
            # The timezone argument is ignored by PHP when the string carries an offset of its own
            $readIn = $serverTime ? new DateTimeZone(date_default_timezone_get()) : static::timezone($timezone);
            $parsed = new DateTimeImmutable($value, $readIn);
            if ($serverTime) {
                $parsed = $parsed->setTimezone(static::timezone($timezone));
            }
        } catch (Throwable) {
            return null;
        }
        return $parsed->format(DateTimeInterface::ATOM);
    }

    /**
     * The configured timezone, or PHP's default one when the name is empty or not a known timezone.
     */
    public static function timezone(?string $name): DateTimeZone
    {
        $name = trim((string) $name);
        if ($name !== '') {
            try {
                return new DateTimeZone($name);
            } catch (Throwable) {
                # Fall through to the default: a typo in YAML should not take the page down
            }
        }
        return new DateTimeZone(date_default_timezone_get());
    }

    /**
     * A strict `Y-m-d` that exists in the calendar (so `2027-02-30` is not a date).
     */
    protected static function isDate(string $value): bool
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return false;
        }
        [$year, $month, $day] = array_map('intval', explode('-', $value));
        return checkdate($month, $day, $year);
    }

    /**
     * The string value of a field, a string, or a DateTimeInterface in the given format.
     */
    protected static function scalar(mixed $value, string $dateTimeFormat): string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format($dateTimeFormat);
        }
        if ($value instanceof DBField) {
            $value = $value->getValue();
        }
        return is_scalar($value) ? trim((string) $value) : '';
    }
}
