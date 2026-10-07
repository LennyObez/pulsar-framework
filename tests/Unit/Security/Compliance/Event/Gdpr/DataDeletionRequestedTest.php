<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\Compliance\Event\Gdpr;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\Compliance\Event\Gdpr\DataDeletionRequested;

#[CoversClass(DataDeletionRequested::class)]
final class DataDeletionRequestedTest extends TestCase
{
    private function createEvent(): DataDeletionRequested
    {
        return new DataDeletionRequested(
            eventId: 'evt-gdpr-dd-001',
            occurredAt: new DateTimeImmutable('2026-03-02T10:00:00+00:00'),
            correlationId: 'corr-gdpr-dd-1',
            nonce: 'nonce-gdpr-dd-1',
            subjectId: 'subject-del-1',
            requesterIdentity: 'user@example.eu',
            deletionScope: 'full_account',
            legalBasis: 'art17_right_to_erasure',
        );
    }

    #[Test]
    public function regulationReturnsGdpr(): void
    {
        self::assertSame('gdpr', $this->createEvent()->regulation());
    }

    #[Test]
    public function eventTypeReturnsDataDeletionRequested(): void
    {
        self::assertSame('data_deletion_requested', $this->createEvent()->eventType());
    }

    #[Test]
    public function toArrayIncludesAllFields(): void
    {
        $array = $this->createEvent()->toArray();

        self::assertSame('evt-gdpr-dd-001', $array['event_id']);
        self::assertSame('subject-del-1', $array['subject_id']);
        self::assertSame('user@example.eu', $array['requester_identity']);
        self::assertSame('full_account', $array['deletion_scope']);
        self::assertSame('art17_right_to_erasure', $array['legal_basis']);
        self::assertSame(1, $array['schema_version']);
    }

    #[Test]
    public function fromArrayRoundTrips(): void
    {
        $original = $this->createEvent();
        $restored = DataDeletionRequested::fromArray($original->toArray());

        self::assertSame($original->eventId, $restored->eventId);
        self::assertSame($original->subjectId, $restored->subjectId);
        self::assertSame($original->requesterIdentity, $restored->requesterIdentity);
        self::assertSame($original->deletionScope, $restored->deletionScope);
        self::assertSame($original->legalBasis, $restored->legalBasis);
    }

    #[Test]
    public function fromArrayHandlesMissingFields(): void
    {
        $event = DataDeletionRequested::fromArray([]);

        self::assertSame('', $event->eventId);
        self::assertSame('', $event->subjectId);
        self::assertSame('', $event->requesterIdentity);
        self::assertSame('', $event->deletionScope);
        self::assertSame('', $event->legalBasis);
    }

    #[Test]
    public function schemaVersionIsOne(): void
    {
        self::assertSame(1, DataDeletionRequested::SCHEMA_VERSION);
    }
}
