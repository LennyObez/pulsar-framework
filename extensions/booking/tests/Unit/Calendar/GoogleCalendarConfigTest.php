<?php

declare(strict_types=1);

namespace Pulsar\Extension\Booking\Tests\Unit\Calendar;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Booking\Calendar\GoogleCalendarConfig;

#[CoversClass(GoogleCalendarConfig::class)]
final class GoogleCalendarConfigTest extends TestCase
{
    #[Test]
    public function constructorSetsProperties(): void
    {
        $config = new GoogleCalendarConfig(
            calendarId: 'cal@group.calendar.google.com',
            serviceAccountKeyPath: '/etc/gcloud/key.json',
            enabled: true,
        );

        self::assertSame('cal@group.calendar.google.com', $config->calendarId);
        self::assertSame('/etc/gcloud/key.json', $config->serviceAccountKeyPath);
        self::assertTrue($config->enabled);
    }

    #[Test]
    public function fromArrayParsesValidData(): void
    {
        $config = GoogleCalendarConfig::fromArray([
            'calendar_id' => 'test-cal-id',
            'service_account_key_path' => '/tmp/key.json',
            'enabled' => true,
        ]);

        self::assertSame('test-cal-id', $config->calendarId);
        self::assertSame('/tmp/key.json', $config->serviceAccountKeyPath);
        self::assertTrue($config->enabled);
    }

    #[Test]
    public function fromArrayDefaultsToEmptyStringsWhenMissing(): void
    {
        $config = GoogleCalendarConfig::fromArray([]);

        self::assertSame('', $config->calendarId);
        self::assertSame('', $config->serviceAccountKeyPath);
        self::assertFalse($config->enabled);
    }

    #[Test]
    public function fromArrayIgnoresNonStringValues(): void
    {
        $config = GoogleCalendarConfig::fromArray([
            'calendar_id' => 42,
            'service_account_key_path' => false,
            'enabled' => 1,
        ]);

        self::assertSame('', $config->calendarId);
        self::assertSame('', $config->serviceAccountKeyPath);
        self::assertTrue($config->enabled);
    }
}
