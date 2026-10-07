<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\EventStore;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\EventStore\ContentEvent;
use Pulsar\Extension\Cms\EventStore\ContentEventService;
use ReflectionClass;

use function count;
use function strlen;

#[CoversClass(ContentEventService::class)]
#[CoversClass(ContentEvent::class)]
final class ContentEventServiceTest extends TestCase
{
    private const string CONTENT_ID = '01912345-6789-7abc-8def-0123456789ab';
    private const string ACTOR_ID = '01912345-6789-7abc-8def-0123456789cd';

    // ── Evidence hash computation ────────────────────────────────────

    #[Test]
    public function computeEvidenceHashReturnsBlake2bHex(): void
    {
        $hash = ContentEventService::computeEvidenceHash(
            self::CONTENT_ID,
            1,
            'ContentCreated',
            ['title' => 'Test'],
        );

        self::assertNotEmpty($hash);
        // BLAKE2b produces a 128-char hex string (64 bytes)
        self::assertSame(128, strlen($hash));
        self::assertMatchesRegularExpression('/^[a-f0-9]+$/', $hash);
    }

    #[Test]
    public function computeEvidenceHashDeterministic(): void
    {
        $hash1 = ContentEventService::computeEvidenceHash(self::CONTENT_ID, 1, 'Created', ['k' => 'v']);
        $hash2 = ContentEventService::computeEvidenceHash(self::CONTENT_ID, 1, 'Created', ['k' => 'v']);

        self::assertSame($hash1, $hash2);
    }

    #[Test]
    public function computeEvidenceHashChangesWithDifferentSequence(): void
    {
        $hash1 = ContentEventService::computeEvidenceHash(self::CONTENT_ID, 1, 'Created', ['k' => 'v']);
        $hash2 = ContentEventService::computeEvidenceHash(self::CONTENT_ID, 2, 'Created', ['k' => 'v']);

        self::assertNotSame($hash1, $hash2);
    }

    #[Test]
    public function computeEvidenceHashChangesWithDifferentEventType(): void
    {
        $hash1 = ContentEventService::computeEvidenceHash(self::CONTENT_ID, 1, 'Created', ['k' => 'v']);
        $hash2 = ContentEventService::computeEvidenceHash(self::CONTENT_ID, 1, 'Updated', ['k' => 'v']);

        self::assertNotSame($hash1, $hash2);
    }

    #[Test]
    public function computeEvidenceHashChangesWithDifferentPayload(): void
    {
        $hash1 = ContentEventService::computeEvidenceHash(self::CONTENT_ID, 1, 'Created', ['title' => 'A']);
        $hash2 = ContentEventService::computeEvidenceHash(self::CONTENT_ID, 1, 'Created', ['title' => 'B']);

        self::assertNotSame($hash1, $hash2);
    }

    #[Test]
    public function computeEvidenceHashChangesWithDifferentContentId(): void
    {
        $otherId = '01912345-6789-7abc-8def-000000000001';
        $hash1 = ContentEventService::computeEvidenceHash(self::CONTENT_ID, 1, 'Created', []);
        $hash2 = ContentEventService::computeEvidenceHash($otherId, 1, 'Created', []);

        self::assertNotSame($hash1, $hash2);
    }

    // ── ContentEvent entity ──────────────────────────────────────────

    #[Test]
    public function contentEventConstruction(): void
    {
        $now = new DateTimeImmutable();
        $event = new ContentEvent(
            id: 'evt-001',
            contentId: self::CONTENT_ID,
            sequence: 1,
            eventType: 'ContentCreated',
            payload: ['title' => 'Hello'],
            actorId: self::ACTOR_ID,
            reason: 'Initial creation',
            evidenceHash: 'abcdef',
            createdAt: $now,
        );

        self::assertSame('evt-001', $event->id);
        self::assertSame(self::CONTENT_ID, $event->contentId);
        self::assertSame(1, $event->sequence);
        self::assertSame('ContentCreated', $event->eventType);
        self::assertSame(['title' => 'Hello'], $event->payload);
        self::assertSame(self::ACTOR_ID, $event->actorId);
        self::assertSame('Initial creation', $event->reason);
        self::assertSame('abcdef', $event->evidenceHash);
        self::assertSame($now, $event->createdAt);
    }

    #[Test]
    public function contentEventNullableReason(): void
    {
        $event = new ContentEvent(
            id: 'evt-002',
            contentId: self::CONTENT_ID,
            sequence: 2,
            eventType: 'Updated',
            payload: [],
            actorId: self::ACTOR_ID,
            reason: null,
            evidenceHash: 'def123',
            createdAt: new DateTimeImmutable(),
        );

        self::assertNull($event->reason);
    }

    #[Test]
    public function contentEventIsReadonly(): void
    {
        $reflection = new ReflectionClass(ContentEvent::class);
        self::assertTrue($reflection->isReadOnly());
    }

    // ── Sequence monotonicity (value object verification) ────────────

    #[Test]
    public function sequentialEventsHaveIncreasingSequenceNumbers(): void
    {
        $events = [];

        for ($i = 1; $i <= 5; $i++) {
            $events[] = new ContentEvent(
                id: "evt-{$i}",
                contentId: self::CONTENT_ID,
                sequence: $i,
                eventType: 'Updated',
                payload: ['version' => $i],
                actorId: self::ACTOR_ID,
                reason: null,
                evidenceHash: ContentEventService::computeEvidenceHash(
                    self::CONTENT_ID,
                    $i,
                    'Updated',
                    ['version' => $i],
                ),
                createdAt: new DateTimeImmutable(),
            );
        }

        for ($i = 1; $i < count($events); $i++) {
            self::assertGreaterThan($events[$i - 1]->sequence, $events[$i]->sequence);
        }
    }
}
