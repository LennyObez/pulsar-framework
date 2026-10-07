<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Ui\Embeddable;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Ui\Embeddable\TimelineEntry;

#[CoversClass(TimelineEntry::class)]
final class TimelineEntryTest extends TestCase
{
    #[Test]
    public function constructorStoresAllFields(): void
    {
        $entry = new TimelineEntry(
            title: 'Deployed v2.0',
            description: 'New version released to production',
            timestamp: '2026-03-15T10:00:00Z',
            status: 'success',
            icon: 'rocket',
        );

        self::assertSame('Deployed v2.0', $entry->title);
        self::assertSame('New version released to production', $entry->description);
        self::assertSame('2026-03-15T10:00:00Z', $entry->timestamp);
        self::assertSame('success', $entry->status);
        self::assertSame('rocket', $entry->icon);
    }

    #[Test]
    public function statusAndIconDefaultToNull(): void
    {
        $entry = new TimelineEntry('Event', 'Something happened', '2026-01-01');

        self::assertNull($entry->status);
        self::assertNull($entry->icon);
    }
}
