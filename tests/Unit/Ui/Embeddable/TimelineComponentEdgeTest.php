<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Ui\Embeddable;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Ui\Embeddable\TimelineComponent;
use Pulsar\Ui\Embeddable\TimelineEntry;

/**
 * Edge case tests for TimelineComponent and TimelineEntry.
 */
#[CoversClass(TimelineComponent::class)]
#[CoversClass(TimelineEntry::class)]
final class TimelineComponentEdgeTest extends TestCase
{
    #[Test]
    public function tagNameReturnsPulsarTimeline(): void
    {
        $timeline = new TimelineComponent();

        self::assertSame('pulsar-timeline', $timeline->tagName());
    }

    #[Test]
    public function renderEmptyTimeline(): void
    {
        $timeline = new TimelineComponent();

        $html = $timeline->render();

        self::assertStringContainsString('<ol class="pulsar-timeline"', $html);
        self::assertStringContainsString('</ol>', $html);
        self::assertStringNotContainsString('<li', $html);
    }

    #[Test]
    public function renderWithEntries(): void
    {
        $timeline = new TimelineComponent();
        $timeline->entries([
            new TimelineEntry('Deployed', 'Release v1.0', '2025-01-01T10:00:00Z'),
            new TimelineEntry('Tested', 'All tests pass', '2025-01-01T09:00:00Z'),
        ]);

        $html = $timeline->render();

        self::assertStringContainsString('Deployed', $html);
        self::assertStringContainsString('Release v1.0', $html);
        self::assertStringContainsString('2025-01-01T10:00:00Z', $html);
        self::assertStringContainsString('Tested', $html);
        self::assertSame(2, substr_count($html, 'pulsar-timeline-entry'));
    }

    #[Test]
    public function addEntryAppendsToExisting(): void
    {
        $timeline = new TimelineComponent();
        $timeline->entries([
            new TimelineEntry('First', 'desc', '2025-01-01'),
        ]);
        $timeline->addEntry(new TimelineEntry('Second', 'desc', '2025-01-02'));

        $html = $timeline->render();

        self::assertStringContainsString('First', $html);
        self::assertStringContainsString('Second', $html);
    }

    #[Test]
    public function renderWithStatus(): void
    {
        $timeline = new TimelineComponent();
        $timeline->entries([
            new TimelineEntry('Done', 'Complete', '2025-01-01', status: 'success'),
        ]);

        $html = $timeline->render();

        self::assertStringContainsString('pulsar-timeline-success', $html);
    }

    #[Test]
    public function renderWithoutStatus(): void
    {
        $timeline = new TimelineComponent();
        $timeline->entries([
            new TimelineEntry('Pending', 'Waiting', '2025-01-01'),
        ]);

        $html = $timeline->render();

        self::assertStringNotContainsString('pulsar-timeline-success', $html);
        self::assertStringNotContainsString('pulsar-timeline-error', $html);
    }

    #[Test]
    public function renderEscapesHtmlInEntryFields(): void
    {
        $timeline = new TimelineComponent();
        $timeline->entries([
            new TimelineEntry('<b>Bold</b>', '<script>x</script>', '2025-01-01', status: '<img>'),
        ]);

        $html = $timeline->render();

        self::assertStringNotContainsString('<b>Bold</b>', $html);
        self::assertStringNotContainsString('<script>x</script>', $html);
        self::assertStringContainsString('&lt;b&gt;Bold&lt;/b&gt;', $html);
    }

    #[Test]
    public function renderContainsAccessibleStructure(): void
    {
        $timeline = new TimelineComponent();
        $timeline->entries([
            new TimelineEntry('Event', 'Desc', '2025-06-01'),
        ]);

        $html = $timeline->render();

        self::assertStringContainsString('role="list"', $html);
        self::assertStringContainsString('role="listitem"', $html);
        self::assertStringContainsString('<time datetime=', $html);
    }

    #[Test]
    public function timelineEntryProperties(): void
    {
        $entry = new TimelineEntry('Title', 'Desc', '2025-01-01T00:00:00Z', 'warning', 'alert-icon');

        self::assertSame('Title', $entry->title);
        self::assertSame('Desc', $entry->description);
        self::assertSame('2025-01-01T00:00:00Z', $entry->timestamp);
        self::assertSame('warning', $entry->status);
        self::assertSame('alert-icon', $entry->icon);
    }

    #[Test]
    public function timelineEntryDefaults(): void
    {
        $entry = new TimelineEntry('T', 'D', '2025-01-01');

        self::assertNull($entry->status);
        self::assertNull($entry->icon);
    }

    #[Test]
    public function renderWrapsInCustomElement(): void
    {
        $timeline = new TimelineComponent();

        $html = $timeline->render();

        self::assertStringStartsWith('<pulsar-timeline', $html);
        self::assertStringEndsWith('</pulsar-timeline>', $html);
    }

    #[Test]
    public function entriesMethodReturnsSelfForChaining(): void
    {
        $timeline = new TimelineComponent();
        $result = $timeline->entries([]);

        self::assertSame($timeline, $result);
    }

    #[Test]
    public function addEntryReturnsSelfForChaining(): void
    {
        $timeline = new TimelineComponent();
        $result = $timeline->addEntry(new TimelineEntry('X', 'Y', 'Z'));

        self::assertSame($timeline, $result);
    }
}
