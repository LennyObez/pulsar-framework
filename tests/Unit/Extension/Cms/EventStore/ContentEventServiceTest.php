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
use function in_array;
use function strlen;

#[CoversClass(ContentEventService::class)]
#[CoversClass(ContentEvent::class)]
final class ContentEventServiceTest extends TestCase
{
    private const string CONTENT_ID = '01912345-6789-7abc-8def-0123456789ab';
    private const string ACTOR_ID = '01912345-6789-7abc-8def-0123456789cd';

    protected function setUp(): void
    {
        if (!in_array('blake2b', hash_algos(), true)) {
            self::markTestSkipped('blake2b hash algorithm is not available in this PHP build');
        }
    }

    // ── Evidence hash computation ────────────────────────────────────

    #[Test]
    public function test_compute_evidence_hash_returns_blake2b_hex(): void
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
    public function test_compute_evidence_hash_deterministic(): void
    {
        $hash1 = ContentEventService::computeEvidenceHash(self::CONTENT_ID, 1, 'Created', ['k' => 'v']);
        $hash2 = ContentEventService::computeEvidenceHash(self::CONTENT_ID, 1, 'Created', ['k' => 'v']);

        self::assertSame($hash1, $hash2);
    }

    #[Test]
    public function test_compute_evidence_hash_changes_with_different_sequence(): void
    {
        $hash1 = ContentEventService::computeEvidenceHash(self::CONTENT_ID, 1, 'Created', ['k' => 'v']);
        $hash2 = ContentEventService::computeEvidenceHash(self::CONTENT_ID, 2, 'Created', ['k' => 'v']);

        self::assertNotSame($hash1, $hash2);
    }

    #[Test]
    public function test_compute_evidence_hash_changes_with_different_event_type(): void
    {
        $hash1 = ContentEventService::computeEvidenceHash(self::CONTENT_ID, 1, 'Created', ['k' => 'v']);
        $hash2 = ContentEventService::computeEvidenceHash(self::CONTENT_ID, 1, 'Updated', ['k' => 'v']);

        self::assertNotSame($hash1, $hash2);
    }

    #[Test]
    public function test_compute_evidence_hash_changes_with_different_payload(): void
    {
        $hash1 = ContentEventService::computeEvidenceHash(self::CONTENT_ID, 1, 'Created', ['title' => 'A']);
        $hash2 = ContentEventService::computeEvidenceHash(self::CONTENT_ID, 1, 'Created', ['title' => 'B']);

        self::assertNotSame($hash1, $hash2);
    }

    #[Test]
    public function test_compute_evidence_hash_changes_with_different_content_id(): void
    {
        $otherId = '01912345-6789-7abc-8def-000000000001';
        $hash1 = ContentEventService::computeEvidenceHash(self::CONTENT_ID, 1, 'Created', []);
        $hash2 = ContentEventService::computeEvidenceHash($otherId, 1, 'Created', []);

        self::assertNotSame($hash1, $hash2);
    }

    // ── ContentEvent entity ──────────────────────────────────────────

    #[Test]
    public function test_content_event_construction(): void
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
    public function test_content_event_nullable_reason(): void
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
    public function test_content_event_is_readonly(): void
    {
        $reflection = new ReflectionClass(ContentEvent::class);
        self::assertTrue($reflection->isReadOnly());
    }

    // ── Sequence monotonicity (value object verification) ────────────

    #[Test]
    public function test_sequential_events_have_increasing_sequence_numbers(): void
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
