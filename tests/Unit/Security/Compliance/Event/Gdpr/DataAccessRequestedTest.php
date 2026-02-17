<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\Compliance\Event\Gdpr;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\Compliance\Event\Gdpr\DataAccessRequested;

#[CoversClass(DataAccessRequested::class)]
final class DataAccessRequestedTest extends TestCase
{
    private function createEvent(): DataAccessRequested
    {
        return new DataAccessRequested(
            eventId: 'evt-gdpr-dar-001',
            occurredAt: new DateTimeImmutable('2026-02-15T09:00:00+00:00'),
            correlationId: 'corr-gdpr-2',
            nonce: 'nonce-gdpr-2',
            subjectId: 'subject-xyz',
            requesterIdentity: 'user@example.com',
            dataCategories: ['personal_data', 'usage_logs', 'payment_history'],
            legalBasis: 'dsar_article_15',
        );
    }

    #[Test]
    public function regulationReturnsGdpr(): void
    {
        self::assertSame('gdpr', $this->createEvent()->regulation());
    }

    #[Test]
    public function eventTypeReturnsDataAccessRequested(): void
    {
        self::assertSame('data_access_requested', $this->createEvent()->eventType());
    }

    #[Test]
    public function toArrayIncludesAllFields(): void
    {
        $array = $this->createEvent()->toArray();

        self::assertSame('evt-gdpr-dar-001', $array['event_id']);
        self::assertSame('subject-xyz', $array['subject_id']);
        self::assertSame('user@example.com', $array['requester_identity']);
        self::assertSame(['personal_data', 'usage_logs', 'payment_history'], $array['data_categories']);
        self::assertSame('dsar_article_15', $array['legal_basis']);
        self::assertSame(1, $array['schema_version']);
    }

    #[Test]
    public function fromArrayRoundTrips(): void
    {
        $original = $this->createEvent();
        $restored = DataAccessRequested::fromArray($original->toArray());

        self::assertSame($original->eventId, $restored->eventId);
        self::assertSame($original->subjectId, $restored->subjectId);
        self::assertSame($original->requesterIdentity, $restored->requesterIdentity);
        self::assertSame($original->dataCategories, $restored->dataCategories);
        self::assertSame($original->legalBasis, $restored->legalBasis);
    }

    #[Test]
    public function fromArrayHandlesMissingFields(): void
    {
        $event = DataAccessRequested::fromArray([]);

        self::assertSame('', $event->eventId);
        self::assertSame('', $event->subjectId);
        self::assertSame('', $event->requesterIdentity);
        self::assertSame([], $event->dataCategories);
        self::assertSame('', $event->legalBasis);
    }

    #[Test]
    public function schemaVersionIsOne(): void
    {
        self::assertSame(1, DataAccessRequested::SCHEMA_VERSION);
    }
}
