<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Ui\Embeddable;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Ui\Embeddable\TimelineComponent;
use Pulsar\Ui\Embeddable\TimelineEntry;

#[CoversClass(TimelineComponent::class)]
final class TimelineComponentTest extends TestCase
{
    #[Test]
    public function renders_timeline(): void
    {
        $timeline = new TimelineComponent();
        $timeline->entries([
            new TimelineEntry('Created', 'User created the account', '2026-01-01T10:00:00Z'),
            new TimelineEntry('Verified', 'Email verified', '2026-01-01T11:00:00Z'),
        ]);

        $html = $timeline->render();

        self::assertStringContainsString('<pulsar-timeline', $html);
        self::assertStringContainsString('Created', $html);
        self::assertStringContainsString('Verified', $html);
        self::assertStringContainsString('role="list"', $html);
    }

    #[Test]
    public function tag_name(): void
    {
        self::assertSame('pulsar-timeline', new TimelineComponent()->tagName());
    }

    #[Test]
    public function add_entry(): void
    {
        $timeline = new TimelineComponent();
        $timeline->addEntry(new TimelineEntry('Event', 'Description', '2026-03-15'));

        $html = $timeline->render();

        self::assertStringContainsString('Event', $html);
    }

    #[Test]
    public function entry_with_status(): void
    {
        $timeline = new TimelineComponent();
        $timeline->entries([
            new TimelineEntry('Done', 'Completed', '2026-01-01', status: 'success'),
        ]);

        $html = $timeline->render();

        self::assertStringContainsString('pulsar-timeline-success', $html);
    }

    #[Test]
    public function escapes_content(): void
    {
        $timeline = new TimelineComponent();
        $timeline->entries([
            new TimelineEntry('<b>Bold</b>', 'Desc<script>', '2026-01-01'),
        ]);

        $html = $timeline->render();

        self::assertStringNotContainsString('<b>Bold</b>', $html);
        self::assertStringNotContainsString('<script>', $html);
    }

    #[Test]
    public function datetime_attribute(): void
    {
        $timeline = new TimelineComponent();
        $timeline->entries([
            new TimelineEntry('Event', 'Desc', '2026-03-15T14:30:00Z'),
        ]);

        $html = $timeline->render();

        self::assertStringContainsString('datetime="2026-03-15T14:30:00Z"', $html);
    }

    #[Test]
    public function empty_timeline(): void
    {
        $timeline = new TimelineComponent();
        $html = $timeline->render();

        self::assertStringContainsString('<ol', $html);
        self::assertStringContainsString('</ol>', $html);
    }
}
