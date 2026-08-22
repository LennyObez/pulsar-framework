<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\JustifiedAccess\Event;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\JustifiedAccess\Event\AccessJustificationReviewed;
use Pulsar\Security\JustifiedAccess\Event\BreakTheGlassActivated;
use Pulsar\Security\JustifiedAccess\Event\JustifiedAccessRecorded;
use Pulsar\Security\JustifiedAccess\Event\UnusualAccessPatternDetected;

#[CoversClass(AccessJustificationReviewed::class)]
#[CoversClass(BreakTheGlassActivated::class)]
#[CoversClass(JustifiedAccessRecorded::class)]
#[CoversClass(UnusualAccessPatternDetected::class)]
final class JustifiedAccessEventTest extends TestCase
{
    // ── AccessJustificationReviewed ───────────────────────────────────

    #[Test]
    public function reviewedEventConstruction(): void
    {
        $now = new DateTimeImmutable('2026-01-15T10:30:00+00:00');
        $event = new AccessJustificationReviewed(
            eventId: 'evt-rev-1',
            occurredAt: $now,
            correlationId: 'corr-rev-1',
            nonce: 'nonce-rev',
            justificationId: 'just-001',
            reviewerId: 'reviewer-42',
            previousStatus: 'pending',
            newStatus: 'approved',
            originalActorId: 'actor-99',
        );

        self::assertSame('evt-rev-1', $event->eventId);
        self::assertSame($now, $event->occurredAt);
        self::assertSame('corr-rev-1', $event->correlationId);
        self::assertSame('nonce-rev', $event->nonce);
        self::assertSame('just-001', $event->justificationId);
        self::assertSame('reviewer-42', $event->reviewerId);
        self::assertSame('pending', $event->previousStatus);
        self::assertSame('approved', $event->newStatus);
        self::assertSame('actor-99', $event->originalActorId);
    }

    #[Test]
    public function reviewedEventRegulationAndType(): void
    {
        $event = AccessJustificationReviewed::fromArray([]);

        self::assertSame('multi', $event->regulation());
        self::assertSame('access_justification_reviewed', $event->eventType());
    }

    #[Test]
    public function reviewedEventRoundTrip(): void
    {
        $now = new DateTimeImmutable('2026-06-01T12:00:00+00:00');
        $original = new AccessJustificationReviewed(
            eventId: 'evt-rt-1',
            occurredAt: $now,
            correlationId: 'corr-rt',
            nonce: 'nonce-rt',
            justificationId: 'just-rt',
            reviewerId: 'rev-rt',
            previousStatus: 'flagged',
            newStatus: 'escalated',
            originalActorId: 'actor-rt',
        );

        $restored = AccessJustificationReviewed::fromArray($original->toArray());

        self::assertSame($original->eventId, $restored->eventId);
        self::assertSame($original->justificationId, $restored->justificationId);
        self::assertSame($original->reviewerId, $restored->reviewerId);
        self::assertSame($original->previousStatus, $restored->previousStatus);
        self::assertSame($original->newStatus, $restored->newStatus);
        self::assertSame($original->originalActorId, $restored->originalActorId);
    }

    #[Test]
    public function reviewedEventFromArrayDefaults(): void
    {
        $event = AccessJustificationReviewed::fromArray([]);

        self::assertSame('', $event->eventId);
        self::assertSame('', $event->justificationId);
        self::assertSame('', $event->reviewerId);
        self::assertSame('', $event->previousStatus);
        self::assertSame('', $event->newStatus);
        self::assertSame('', $event->originalActorId);
    }

    #[Test]
    public function reviewedEventToArrayIncludesSchemaVersion(): void
    {
        $event = AccessJustificationReviewed::fromArray([
            'justification_id' => 'j-1',
        ]);

        $array = $event->toArray();

        self::assertSame(1, $array['schema_version']);
        self::assertSame('j-1', $array['justification_id']);
        self::assertArrayHasKey('event_id', $array);
        self::assertArrayHasKey('occurred_at', $array);
    }

    // ── BreakTheGlassActivated ────────────────────────────────────────

    #[Test]
    public function breakTheGlassConstruction(): void
    {
        $now = new DateTimeImmutable('2026-03-10T08:00:00+00:00');
        $event = new BreakTheGlassActivated(
            eventId: 'evt-btg-1',
            occurredAt: $now,
            correlationId: 'corr-btg',
            nonce: 'nonce-btg',
            justificationId: 'just-btg',
            actorId: 'actor-btg',
            resourceType: 'patient_record',
            resourceId: 'patient-123',
            justificationText: 'Emergency life-threatening situation',
            durationSeconds: 1800,
        );

        self::assertSame('evt-btg-1', $event->eventId);
        self::assertSame('just-btg', $event->justificationId);
        self::assertSame('actor-btg', $event->actorId);
        self::assertSame('patient_record', $event->resourceType);
        self::assertSame('patient-123', $event->resourceId);
        self::assertSame('Emergency life-threatening situation', $event->justificationText);
        self::assertSame(1800, $event->durationSeconds);
    }

    #[Test]
    public function breakTheGlassRegulationAndType(): void
    {
        $event = BreakTheGlassActivated::fromArray([]);

        self::assertSame('multi', $event->regulation());
        self::assertSame('break_the_glass_activated', $event->eventType());
    }

    #[Test]
    public function breakTheGlassRoundTrip(): void
    {
        $now = new DateTimeImmutable('2026-04-15T14:30:00+00:00');
        $original = new BreakTheGlassActivated(
            eventId: 'evt-btg-rt',
            occurredAt: $now,
            correlationId: 'corr-btg-rt',
            nonce: 'nonce-btg-rt',
            justificationId: 'just-btg-rt',
            actorId: 'actor-btg-rt',
            resourceType: 'financial_record',
            resourceId: 'tx-42',
            justificationText: 'Fraud investigation required',
            durationSeconds: 3600,
        );

        $restored = BreakTheGlassActivated::fromArray($original->toArray());

        self::assertSame($original->eventId, $restored->eventId);
        self::assertSame($original->justificationId, $restored->justificationId);
        self::assertSame($original->actorId, $restored->actorId);
        self::assertSame($original->resourceType, $restored->resourceType);
        self::assertSame($original->resourceId, $restored->resourceId);
        self::assertSame($original->justificationText, $restored->justificationText);
        self::assertSame($original->durationSeconds, $restored->durationSeconds);
    }

    #[Test]
    public function breakTheGlassFromArrayDefaults(): void
    {
        $event = BreakTheGlassActivated::fromArray([]);

        self::assertSame('', $event->eventId);
        self::assertSame('', $event->justificationId);
        self::assertSame('', $event->actorId);
        self::assertSame('', $event->resourceType);
        self::assertSame('', $event->resourceId);
        self::assertSame('', $event->justificationText);
        self::assertSame(900, $event->durationSeconds);
    }

    #[Test]
    public function breakTheGlassToArrayIncludesDuration(): void
    {
        $event = BreakTheGlassActivated::fromArray([
            'duration_seconds' => 7200,
            'actor_id' => 'doc-1',
        ]);

        $array = $event->toArray();

        self::assertSame(7200, $array['duration_seconds']);
        self::assertSame('doc-1', $array['actor_id']);
        self::assertSame(1, $array['schema_version']);
    }

    // ── JustifiedAccessRecorded ───────────────────────────────────────

    #[Test]
    public function justifiedAccessRecordedConstruction(): void
    {
        $now = new DateTimeImmutable('2026-02-20T16:45:00+00:00');
        $event = new JustifiedAccessRecorded(
            eventId: 'evt-jar-1',
            occurredAt: $now,
            correlationId: 'corr-jar',
            nonce: 'nonce-jar',
            justificationId: 'just-jar',
            actorId: 'user-55',
            resourceType: 'customer_data',
            resourceId: 'cust-789',
            category: 'regulatory',
            dataClassification: 'confidential',
        );

        self::assertSame('evt-jar-1', $event->eventId);
        self::assertSame('just-jar', $event->justificationId);
        self::assertSame('user-55', $event->actorId);
        self::assertSame('customer_data', $event->resourceType);
        self::assertSame('cust-789', $event->resourceId);
        self::assertSame('regulatory', $event->category);
        self::assertSame('confidential', $event->dataClassification);
    }

    #[Test]
    public function justifiedAccessRecordedRegulationAndType(): void
    {
        $event = JustifiedAccessRecorded::fromArray([]);

        self::assertSame('multi', $event->regulation());
        self::assertSame('justified_access_recorded', $event->eventType());
    }

    #[Test]
    public function justifiedAccessRecordedRoundTrip(): void
    {
        $now = new DateTimeImmutable('2026-05-10T09:15:00+00:00');
        $original = new JustifiedAccessRecorded(
            eventId: 'evt-jar-rt',
            occurredAt: $now,
            correlationId: 'corr-jar-rt',
            nonce: 'nonce-jar-rt',
            justificationId: 'just-jar-rt',
            actorId: 'actor-jar-rt',
            resourceType: 'account',
            resourceId: 'acc-42',
            category: 'dispute',
            dataClassification: 'restricted',
        );

        $restored = JustifiedAccessRecorded::fromArray($original->toArray());

        self::assertSame($original->eventId, $restored->eventId);
        self::assertSame($original->justificationId, $restored->justificationId);
        self::assertSame($original->actorId, $restored->actorId);
        self::assertSame($original->resourceType, $restored->resourceType);
        self::assertSame($original->resourceId, $restored->resourceId);
        self::assertSame($original->category, $restored->category);
        self::assertSame($original->dataClassification, $restored->dataClassification);
    }

    #[Test]
    public function justifiedAccessRecordedFromArrayDefaults(): void
    {
        $event = JustifiedAccessRecorded::fromArray([]);

        self::assertSame('', $event->justificationId);
        self::assertSame('', $event->actorId);
        self::assertSame('', $event->resourceType);
        self::assertSame('', $event->resourceId);
        self::assertSame('', $event->category);
        self::assertSame('', $event->dataClassification);
    }

    // ── UnusualAccessPatternDetected ──────────────────────────────────

    #[Test]
    public function unusualAccessConstruction(): void
    {
        $now = new DateTimeImmutable('2026-07-04T00:00:00+00:00');
        $event = new UnusualAccessPatternDetected(
            eventId: 'evt-uap-1',
            occurredAt: $now,
            correlationId: 'corr-uap',
            nonce: 'nonce-uap',
            actorId: 'suspect-1',
            uniqueResourcesAccessed: 150,
            threshold: 50,
            windowSeconds: 3600,
        );

        self::assertSame('evt-uap-1', $event->eventId);
        self::assertSame('suspect-1', $event->actorId);
        self::assertSame(150, $event->uniqueResourcesAccessed);
        self::assertSame(50, $event->threshold);
        self::assertSame(3600, $event->windowSeconds);
    }

    #[Test]
    public function unusualAccessRegulationAndType(): void
    {
        $event = UnusualAccessPatternDetected::fromArray([]);

        self::assertSame('multi', $event->regulation());
        self::assertSame('unusual_access_pattern_detected', $event->eventType());
    }

    #[Test]
    public function unusualAccessRoundTrip(): void
    {
        $now = new DateTimeImmutable('2026-08-20T18:30:00+00:00');
        $original = new UnusualAccessPatternDetected(
            eventId: 'evt-uap-rt',
            occurredAt: $now,
            correlationId: 'corr-uap-rt',
            nonce: 'nonce-uap-rt',
            actorId: 'actor-uap-rt',
            uniqueResourcesAccessed: 200,
            threshold: 100,
            windowSeconds: 7200,
        );

        $restored = UnusualAccessPatternDetected::fromArray($original->toArray());

        self::assertSame($original->eventId, $restored->eventId);
        self::assertSame($original->actorId, $restored->actorId);
        self::assertSame($original->uniqueResourcesAccessed, $restored->uniqueResourcesAccessed);
        self::assertSame($original->threshold, $restored->threshold);
        self::assertSame($original->windowSeconds, $restored->windowSeconds);
    }

    #[Test]
    public function unusualAccessFromArrayDefaults(): void
    {
        $event = UnusualAccessPatternDetected::fromArray([]);

        self::assertSame('', $event->actorId);
        self::assertSame(0, $event->uniqueResourcesAccessed);
        self::assertSame(50, $event->threshold);
        self::assertSame(3600, $event->windowSeconds);
    }

    #[Test]
    public function unusualAccessToArrayIncludesIntFields(): void
    {
        $event = UnusualAccessPatternDetected::fromArray([
            'unique_resources_accessed' => 75,
            'threshold' => 25,
            'window_seconds' => 1800,
        ]);

        $array = $event->toArray();

        self::assertSame(75, $array['unique_resources_accessed']);
        self::assertSame(25, $array['threshold']);
        self::assertSame(1800, $array['window_seconds']);
        self::assertSame(1, $array['schema_version']);
    }

    // ── Cross-event regulation ────────────────────────────────────────

    #[Test]
    #[DataProvider('allEventsRegulationProvider')]
    public function allJustifiedAccessEventsReturnMultiRegulation(string $eventType): void
    {
        $event = match ($eventType) {
            'reviewed' => AccessJustificationReviewed::fromArray([]),
            'break_glass' => BreakTheGlassActivated::fromArray([]),
            'recorded' => JustifiedAccessRecorded::fromArray([]),
            'unusual' => UnusualAccessPatternDetected::fromArray([]),
            default => self::fail("Unknown event type: {$eventType}"),
        };

        self::assertSame('multi', $event->regulation());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function allEventsRegulationProvider(): iterable
    {
        yield 'AccessJustificationReviewed' => ['reviewed'];
        yield 'BreakTheGlassActivated' => ['break_glass'];
        yield 'JustifiedAccessRecorded' => ['recorded'];
        yield 'UnusualAccessPatternDetected' => ['unusual'];
    }

    #[Test]
    #[DataProvider('eventTypeProvider')]
    public function eventTypeValues(string $eventKey, string $expectedType): void
    {
        $event = match ($eventKey) {
            'reviewed' => AccessJustificationReviewed::fromArray([]),
            'break_glass' => BreakTheGlassActivated::fromArray([]),
            'recorded' => JustifiedAccessRecorded::fromArray([]),
            'unusual' => UnusualAccessPatternDetected::fromArray([]),
            default => self::fail("Unknown event key: {$eventKey}"),
        };

        self::assertSame($expectedType, $event->eventType());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function eventTypeProvider(): iterable
    {
        yield 'AccessJustificationReviewed' => ['reviewed', 'access_justification_reviewed'];
        yield 'BreakTheGlassActivated' => ['break_glass', 'break_the_glass_activated'];
        yield 'JustifiedAccessRecorded' => ['recorded', 'justified_access_recorded'];
        yield 'UnusualAccessPatternDetected' => ['unusual', 'unusual_access_pattern_detected'];
    }
}
