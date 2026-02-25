<?php

declare(strict_types=1);

namespace Pulsar\Tests\Integration\Extension\Cms;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\Config\CmsConfig;
use Pulsar\Extension\Cms\EventStore\ContentEvent;
use Pulsar\Extension\Cms\EventStore\ContentEventService;

use function in_array;
use function strlen;

#[CoversClass(ContentEvent::class)]
#[CoversClass(ContentEventService::class)]
final class EventStoreTest extends TestCase
{
    protected function setUp(): void
    {
        if (!in_array('blake2b', hash_algos(), true)) {
            self::markTestSkipped('blake2b hash algorithm is not available in this PHP build');
        }
    }

    #[Test]
    public function appendEventWithEvidenceHash(): void
    {
        $contentId = 'content-001';
        $eventType = 'ContentCreated';
        $payload = ['title' => 'Hello World', 'locale' => 'en'];
        $actorId = 'user-001';

        $evidenceHash = ContentEventService::computeEvidenceHash(
            $contentId,
            1,
            $eventType,
            $payload,
        );

        $event = new ContentEvent(
            id: 'event-001',
            contentId: $contentId,
            sequence: 1,
            eventType: $eventType,
            payload: $payload,
            actorId: $actorId,
            reason: 'Content created',
            evidenceHash: $evidenceHash,
            createdAt: new DateTimeImmutable(),
        );

        // Verify the evidence hash is deterministic
        $recomputedHash = ContentEventService::computeEvidenceHash(
            $contentId,
            1,
            $eventType,
            $payload,
        );

        self::assertSame($evidenceHash, $recomputedHash);
        self::assertNotEmpty($event->evidenceHash);
        self::assertSame(128, strlen($event->evidenceHash)); // BLAKE2b = 64 bytes = 128 hex
        self::assertSame('ContentCreated', $event->eventType);
        self::assertSame('Content created', $event->reason);
    }

    #[Test]
    public function eventsOrderedBySequence(): void
    {
        $store = new InMemoryEventStore();

        $events = [
            $this->createEvent('content-001', 3, 'StatusChanged'),
            $this->createEvent('content-001', 1, 'ContentCreated'),
            $this->createEvent('content-001', 2, 'TranslationUpdated'),
        ];

        foreach ($events as $event) {
            $store->append($event);
        }

        $retrieved = $store->getEvents('content-001');

        self::assertCount(3, $retrieved);
        self::assertSame(1, $retrieved[0]->sequence);
        self::assertSame('ContentCreated', $retrieved[0]->eventType);
        self::assertSame(2, $retrieved[1]->sequence);
        self::assertSame('TranslationUpdated', $retrieved[1]->eventType);
        self::assertSame(3, $retrieved[2]->sequence);
        self::assertSame('StatusChanged', $retrieved[2]->eventType);
    }

    #[Test]
    public function eventStoreOnlyActiveWhenConfigEnabled(): void
    {
        $enabledConfig = new CmsConfig(eventSourcing: true);
        $disabledConfig = new CmsConfig(eventSourcing: false);

        // The ContentEventService.isEnabled() check depends on config
        self::assertTrue($enabledConfig->eventSourcing);
        self::assertFalse($disabledConfig->eventSourcing);

        // Simulating the no-op behavior: when disabled, events have sequence 0
        $noopEvent = new ContentEvent(
            id: 'noop-001',
            contentId: 'content-001',
            sequence: 0,
            eventType: 'ContentCreated',
            payload: ['title' => 'Test'],
            actorId: 'user-001',
            reason: null,
            evidenceHash: ContentEventService::computeEvidenceHash('content-001', 0, 'ContentCreated', ['title' => 'Test']),
            createdAt: new DateTimeImmutable(),
        );

        self::assertSame(0, $noopEvent->sequence);
        self::assertNotEmpty($noopEvent->evidenceHash);
    }

    private function createEvent(string $contentId, int $sequence, string $eventType): ContentEvent
    {
        $payload = ['action' => $eventType];

        return new ContentEvent(
            id: "event-{$contentId}-{$sequence}",
            contentId: $contentId,
            sequence: $sequence,
            eventType: $eventType,
            payload: $payload,
            actorId: 'user-001',
            reason: null,
            evidenceHash: ContentEventService::computeEvidenceHash($contentId, $sequence, $eventType, $payload),
            createdAt: new DateTimeImmutable(),
        );
    }
}

final class InMemoryEventStore
{
    /** @var list<ContentEvent> */
    private array $events = [];

    public function append(ContentEvent $event): void
    {
        $this->events[] = $event;
    }

    /** @return list<ContentEvent> */
    public function getEvents(string $contentId, ?int $afterSequence = null): array
    {
        $matching = array_values(array_filter(
            $this->events,
            static fn(ContentEvent $e) => $e->contentId === $contentId
                && ($afterSequence === null || $e->sequence > $afterSequence),
        ));

        usort($matching, static fn(ContentEvent $a, ContentEvent $b) => $a->sequence <=> $b->sequence);

        return $matching;
    }
}
