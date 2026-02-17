<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\Compliance\Event\Aml;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\Compliance\Event\Aml\SanctionsChecked;

#[CoversClass(SanctionsChecked::class)]
final class SanctionsCheckedTest extends TestCase
{
    private function createEvent(): SanctionsChecked
    {
        return new SanctionsChecked(
            eventId: 'evt-aml-sc-001',
            occurredAt: new DateTimeImmutable('2026-03-10T11:00:00+00:00'),
            correlationId: 'corr-aml-sc-1',
            nonce: 'nonce-aml-sc-1',
            checkerIdentity: 'sanctions-engine-v4',
            customerPseudonym: 'cust-anon-77',
            sanctionsListVersion: 'ofac-sdn-2026-03-01',
            result: 'clear',
            matchDetails: 'no_matches_found',
        );
    }

    #[Test]
    public function regulationReturnsAml(): void
    {
        self::assertSame('aml', $this->createEvent()->regulation());
    }

    #[Test]
    public function eventTypeReturnsSanctionsChecked(): void
    {
        self::assertSame('sanctions_checked', $this->createEvent()->eventType());
    }

    #[Test]
    public function toArrayIncludesAllFields(): void
    {
        $array = $this->createEvent()->toArray();

        self::assertSame('evt-aml-sc-001', $array['event_id']);
        self::assertSame('sanctions-engine-v4', $array['checker_identity']);
        self::assertSame('cust-anon-77', $array['customer_pseudonym']);
        self::assertSame('ofac-sdn-2026-03-01', $array['sanctions_list_version']);
        self::assertSame('clear', $array['result']);
        self::assertSame('no_matches_found', $array['match_details']);
        self::assertSame(1, $array['schema_version']);
    }

    #[Test]
    public function fromArrayRoundTrips(): void
    {
        $original = $this->createEvent();
        $restored = SanctionsChecked::fromArray($original->toArray());

        self::assertSame($original->eventId, $restored->eventId);
        self::assertSame($original->checkerIdentity, $restored->checkerIdentity);
        self::assertSame($original->customerPseudonym, $restored->customerPseudonym);
        self::assertSame($original->sanctionsListVersion, $restored->sanctionsListVersion);
        self::assertSame($original->result, $restored->result);
        self::assertSame($original->matchDetails, $restored->matchDetails);
    }

    #[Test]
    public function fromArrayHandlesMissingFields(): void
    {
        $event = SanctionsChecked::fromArray([]);

        self::assertSame('', $event->eventId);
        self::assertSame('', $event->checkerIdentity);
        self::assertSame('', $event->customerPseudonym);
        self::assertSame('', $event->sanctionsListVersion);
        self::assertSame('', $event->result);
        self::assertSame('', $event->matchDetails);
    }

    #[Test]
    public function schemaVersionIsOne(): void
    {
        self::assertSame(1, SanctionsChecked::SCHEMA_VERSION);
    }
}
