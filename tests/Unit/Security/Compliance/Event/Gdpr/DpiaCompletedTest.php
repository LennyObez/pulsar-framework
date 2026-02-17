<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\Compliance\Event\Gdpr;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\Compliance\Event\Gdpr\DpiaCompleted;

#[CoversClass(DpiaCompleted::class)]
final class DpiaCompletedTest extends TestCase
{
    private function createEvent(): DpiaCompleted
    {
        return new DpiaCompleted(
            eventId: 'evt-gdpr-dpia-001',
            occurredAt: new DateTimeImmutable('2026-02-20T15:00:00+00:00'),
            correlationId: 'corr-gdpr-4',
            nonce: 'nonce-gdpr-4',
            assessorIdentity: 'dpo@company.eu',
            processingActivity: 'automated_profiling',
            riskLevel: 'high',
            mitigations: ['encryption_at_rest', 'access_logging', 'data_minimization'],
        );
    }

    #[Test]
    public function regulationReturnsGdpr(): void
    {
        self::assertSame('gdpr', $this->createEvent()->regulation());
    }

    #[Test]
    public function eventTypeReturnsDpiaCompleted(): void
    {
        self::assertSame('dpia_completed', $this->createEvent()->eventType());
    }

    #[Test]
    public function toArrayIncludesAllFields(): void
    {
        $array = $this->createEvent()->toArray();

        self::assertSame('evt-gdpr-dpia-001', $array['event_id']);
        self::assertSame('dpo@company.eu', $array['assessor_identity']);
        self::assertSame('automated_profiling', $array['processing_activity']);
        self::assertSame('high', $array['risk_level']);
        self::assertSame(['encryption_at_rest', 'access_logging', 'data_minimization'], $array['mitigations']);
        self::assertSame(1, $array['schema_version']);
    }

    #[Test]
    public function fromArrayRoundTrips(): void
    {
        $original = $this->createEvent();
        $restored = DpiaCompleted::fromArray($original->toArray());

        self::assertSame($original->eventId, $restored->eventId);
        self::assertSame($original->assessorIdentity, $restored->assessorIdentity);
        self::assertSame($original->processingActivity, $restored->processingActivity);
        self::assertSame($original->riskLevel, $restored->riskLevel);
        self::assertSame($original->mitigations, $restored->mitigations);
    }

    #[Test]
    public function fromArrayHandlesMissingFields(): void
    {
        $event = DpiaCompleted::fromArray([]);

        self::assertSame('', $event->eventId);
        self::assertSame('', $event->assessorIdentity);
        self::assertSame('', $event->processingActivity);
        self::assertSame('', $event->riskLevel);
        self::assertSame([], $event->mitigations);
    }

    #[Test]
    public function schemaVersionIsOne(): void
    {
        self::assertSame(1, DpiaCompleted::SCHEMA_VERSION);
    }
}
