<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\Compliance\Event\Gdpr;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\Compliance\Event\Gdpr\DataPortabilityRequested;

#[CoversClass(DataPortabilityRequested::class)]
final class DataPortabilityRequestedTest extends TestCase
{
    private function createEvent(): DataPortabilityRequested
    {
        return new DataPortabilityRequested(
            eventId: 'evt-gdpr-dp-001',
            occurredAt: new DateTimeImmutable('2026-03-01T11:00:00+00:00'),
            correlationId: 'corr-gdpr-3',
            nonce: 'nonce-gdpr-3',
            subjectId: 'subject-port-1',
            requesterIdentity: 'user@example.com',
            format: 'json',
            dataCategories: ['profile', 'orders', 'preferences'],
        );
    }

    #[Test]
    public function regulationReturnsGdpr(): void
    {
        self::assertSame('gdpr', $this->createEvent()->regulation());
    }

    #[Test]
    public function eventTypeReturnsDataPortabilityRequested(): void
    {
        self::assertSame('data_portability_requested', $this->createEvent()->eventType());
    }

    #[Test]
    public function toArrayIncludesAllFields(): void
    {
        $array = $this->createEvent()->toArray();

        self::assertSame('evt-gdpr-dp-001', $array['event_id']);
        self::assertSame('subject-port-1', $array['subject_id']);
        self::assertSame('user@example.com', $array['requester_identity']);
        self::assertSame('json', $array['format']);
        self::assertSame(['profile', 'orders', 'preferences'], $array['data_categories']);
        self::assertSame(1, $array['schema_version']);
    }

    #[Test]
    public function fromArrayRoundTrips(): void
    {
        $original = $this->createEvent();
        $restored = DataPortabilityRequested::fromArray($original->toArray());

        self::assertSame($original->eventId, $restored->eventId);
        self::assertSame($original->subjectId, $restored->subjectId);
        self::assertSame($original->requesterIdentity, $restored->requesterIdentity);
        self::assertSame($original->format, $restored->format);
        self::assertSame($original->dataCategories, $restored->dataCategories);
    }

    #[Test]
    public function fromArrayHandlesMissingFields(): void
    {
        $event = DataPortabilityRequested::fromArray([]);

        self::assertSame('', $event->eventId);
        self::assertSame('', $event->subjectId);
        self::assertSame('', $event->requesterIdentity);
        self::assertSame('', $event->format);
        self::assertSame([], $event->dataCategories);
    }

    #[Test]
    public function schemaVersionIsOne(): void
    {
        self::assertSame(1, DataPortabilityRequested::SCHEMA_VERSION);
    }
}
