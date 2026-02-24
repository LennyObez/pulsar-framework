<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\EventStore;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\EventStore\ContentEvent;
use Pulsar\Extension\Cms\EventStore\ContentEventChainResult;
use Pulsar\Extension\Cms\EventStore\ContentSnapshot;

#[CoversClass(ContentEvent::class)]
#[CoversClass(ContentEventChainResult::class)]
#[CoversClass(ContentSnapshot::class)]
final class EventStoreValueObjectsTest extends TestCase
{
    // -- ContentEvent ---------------------------------------------------------

    #[Test]
    public function contentEventConstructor(): void
    {
        $now = new DateTimeImmutable('2025-06-01T12:00:00+00:00');

        $event = new ContentEvent(
            id: 'ev-01',
            contentId: 'c-01',
            sequence: 1,
            eventType: 'ContentCreated',
            payload: ['title' => 'Hello World'],
            actorId: 'u-01',
            reason: 'Initial creation',
            evidenceHash: hash('sha256', 'test'),
            createdAt: $now,
        );

        self::assertSame('ev-01', $event->id);
        self::assertSame('c-01', $event->contentId);
        self::assertSame(1, $event->sequence);
        self::assertSame('ContentCreated', $event->eventType);
        self::assertSame('Hello World', $event->payload['title']);
        self::assertSame('u-01', $event->actorId);
        self::assertSame('Initial creation', $event->reason);
        self::assertNotEmpty($event->evidenceHash);
    }

    #[Test]
    public function contentEventWithNullReason(): void
    {
        $event = new ContentEvent(
            id: 'ev-02',
            contentId: 'c-02',
            sequence: 5,
            eventType: 'TranslationUpdated',
            payload: [],
            actorId: 'u-02',
            reason: null,
            evidenceHash: 'abc123',
            createdAt: new DateTimeImmutable(),
        );

        self::assertNull($event->reason);
        self::assertSame(5, $event->sequence);
    }

    // -- ContentEventChainResult ----------------------------------------------

    #[Test]
    public function contentEventChainResultValid(): void
    {
        $result = new ContentEventChainResult(
            valid: true,
            events: [],
            brokenLinks: [],
            totalEvents: 10,
        );

        self::assertTrue($result->valid);
        self::assertSame([], $result->brokenLinks);
        self::assertSame(10, $result->totalEvents);
    }

    #[Test]
    public function contentEventChainResultWithBrokenLinks(): void
    {
        $result = new ContentEventChainResult(
            valid: false,
            events: [],
            brokenLinks: [3, 7],
            totalEvents: 10,
        );

        self::assertFalse($result->valid);
        self::assertSame([3, 7], $result->brokenLinks);
    }

    // -- ContentSnapshot ------------------------------------------------------

    #[Test]
    public function contentSnapshotConstructor(): void
    {
        $now = new DateTimeImmutable('2025-06-01T12:00:00+00:00');

        $snapshot = new ContentSnapshot(
            id: 'snap-01',
            contentId: 'c-01',
            snapshotNumber: 1,
            translationsJson: [['locale' => 'en', 'title' => 'Hello']],
            blocksJson: [['type' => 'paragraph', 'content' => 'Body']],
            taxonomyTermIds: ['term-01', 'term-02'],
            evidenceHash: hash('sha256', 'snapshot'),
            reason: 'Published',
            createdBy: 'u-01',
            createdAt: $now,
        );

        self::assertSame('snap-01', $snapshot->id);
        self::assertSame(1, $snapshot->snapshotNumber);
        self::assertCount(1, $snapshot->translationsJson);
        self::assertCount(1, $snapshot->blocksJson);
        self::assertCount(2, $snapshot->taxonomyTermIds);
        self::assertSame('Published', $snapshot->reason);
    }
}
