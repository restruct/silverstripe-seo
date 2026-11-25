<?php

namespace Restruct\Silverstripe\SEO\Tests\Schema;

use Restruct\Silverstripe\SEO\Schema\SchemaDates;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\ORM\FieldType\DBDate;
use SilverStripe\ORM\FieldType\DBDatetime;
use SilverStripe\ORM\FieldType\DBField;
use SilverStripe\ORM\FieldType\DBTime;

/**
 * Dates in structured data are ISO 8601 with the offset of the site's timezone. Records often keep the date and
 * the time of an event in two fields, and the database stores both without a timezone, so the offset has to be
 * added here, and it changes with daylight saving time.
 */
class SchemaDatesTest extends SapphireTest
{
    public function testDateAndTimeCombineWithTheOffsetOfThatDay(): void
    {
        # Winter time in Amsterdam is +01:00, summer time +02:00: the offset is that of the date, not of today
        $this->assertSame(
            '2027-01-14T19:30:00+01:00',
            SchemaDates::combine('2027-01-14', '19:30:00', 'Europe/Amsterdam')
        );
        $this->assertSame(
            '2027-07-14T19:30:00+02:00',
            SchemaDates::combine('2027-07-14', '19:30', 'Europe/Amsterdam')
        );
    }

    public function testDatabaseFieldsCombine(): void
    {
        $date = DBField::create_field(DBDate::class, '2027-03-01');
        $time = DBField::create_field(DBTime::class, '09:00:00');
        $this->assertSame('2027-03-01T09:00:00-05:00', SchemaDates::combine($date, $time, 'America/New_York'));
    }

    public function testWithoutATimeOnlyTheDateIsGiven(): void
    {
        # A date without a time is valid ISO 8601 and what schema.org expects for all-day events
        $this->assertSame('2027-03-01', SchemaDates::combine('2027-03-01', null, 'Europe/Amsterdam'));
        $this->assertSame('2027-03-01', SchemaDates::combine('2027-03-01', '', 'Europe/Amsterdam'));
    }

    public function testWithoutADateThereIsNoValue(): void
    {
        $this->assertNull(SchemaDates::combine(null, '19:30', 'Europe/Amsterdam'));
        $this->assertNull(SchemaDates::combine('', '19:30', 'Europe/Amsterdam'));
        $this->assertNull(SchemaDates::combine(DBField::create_field(DBDate::class, null), null, 'UTC'));
    }

    public function testDatetimeFieldGetsTheOffset(): void
    {
        $value = DBField::create_field(DBDatetime::class, '2027-07-01 08:15:00');
        $this->assertSame('2027-07-01T08:15:00+02:00', SchemaDates::format($value, 'Europe/Amsterdam'));
        $this->assertSame('2027-07-01T08:15:00+00:00', SchemaDates::format($value, 'UTC'));
    }

    public function testDateFieldStaysADate(): void
    {
        $value = DBField::create_field(DBDate::class, '2027-07-01');
        $this->assertSame('2027-07-01', SchemaDates::format($value, 'Europe/Amsterdam'));
    }

    public function testStringsAndDateTimeObjects(): void
    {
        # A string without an offset is local time of the site; one with an offset keeps its own
        $this->assertSame('2027-07-01T08:15:00+02:00', SchemaDates::format('2027-07-01 08:15:00', 'Europe/Amsterdam'));
        $this->assertSame('2027-07-01T08:15:00+05:30', SchemaDates::format('2027-07-01T08:15:00+05:30', 'Europe/Amsterdam'));
        $this->assertSame('2027-07-01', SchemaDates::format('2027-07-01', 'Europe/Amsterdam'));
        $this->assertSame(
            '2027-07-01T08:15:00+00:00',
            SchemaDates::format(new \DateTimeImmutable('2027-07-01 08:15:00', new \DateTimeZone('UTC')), 'Europe/Amsterdam')
        );
        $this->assertNull(SchemaDates::format('', 'UTC'));
        $this->assertNull(SchemaDates::format('not a date', 'UTC'));
    }

    public function testDefaultTimezoneIsPhpsOwn(): void
    {
        $previous = date_default_timezone_get();
        date_default_timezone_set('Asia/Tokyo');
        try {
            $this->assertSame('Asia/Tokyo', SchemaDates::timezone(null)->getName());
            $this->assertSame('Asia/Tokyo', SchemaDates::timezone('')->getName());
            $this->assertSame('2027-07-01T08:15:00+09:00', SchemaDates::combine('2027-07-01', '08:15', null));
        } finally {
            date_default_timezone_set($previous);
        }
    }

    public function testAnUnknownTimezoneFallsBackToThePhpDefault(): void
    {
        $this->assertSame(date_default_timezone_get(), SchemaDates::timezone('Not/AZone')->getName());
    }
}
