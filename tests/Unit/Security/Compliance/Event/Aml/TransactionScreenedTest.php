<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\Compliance\Event\Aml;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\Compliance\Event\Aml\TransactionScreened;

#[CoversClass(TransactionScreened::class)]
final class TransactionScreenedTest extends TestCase
{
    private function createEvent(): TransactionScreened
    {
        return new TransactionScreened(
            eventId: 'evt-aml-ts-001',
            occurredAt: new DateTimeImmutable('2026-03-10T09:30:00+00:00'),
            correlationId: 'corr-aml-1',
            nonce: 'nonce-aml-1',
            screenerIdentity: 'aml-engine-v3',
            transactionId: 'txn-98765',
            customerPseudonym: 'cust-anon-42',
            screeningResult: 'flagged',
            matchedRules: ['large_cash_threshold', 'unusual_jurisdiction'],
        );
    }

    #[Test]
    public function regulationReturnsAml(): void
    {
        self::assertSame('aml', $this->createEvent()->regulation());
    }

    #[Test]
    public function eventTypeReturnsTransactionScreened(): void
    {
        self::assertSame('transaction_screened', $this->createEvent()->eventType());
    }

    #[Test]
    public function toArrayIncludesAllFields(): void
    {
        $array = $this->createEvent()->toArray();

        self::assertSame('evt-aml-ts-001', $array['event_id']);
        self::assertSame('aml-engine-v3', $array['screener_identity']);
        self::assertSame('txn-98765', $array['transaction_id']);
        self::assertSame('cust-anon-42', $array['customer_pseudonym']);
        self::assertSame('flagged', $array['screening_result']);
        self::assertSame(['large_cash_threshold', 'unusual_jurisdiction'], $array['matched_rules']);
        self::assertSame(1, $array['schema_version']);
    }

    #[Test]
    public function fromArrayRoundTrips(): void
    {
        $original = $this->createEvent();
        $restored = TransactionScreened::fromArray($original->toArray());

        self::assertSame($original->eventId, $restored->eventId);
        self::assertSame($original->screenerIdentity, $restored->screenerIdentity);
        self::assertSame($original->transactionId, $restored->transactionId);
        self::assertSame($original->customerPseudonym, $restored->customerPseudonym);
        self::assertSame($original->screeningResult, $restored->screeningResult);
        self::assertSame($original->matchedRules, $restored->matchedRules);
    }

    #[Test]
    public function fromArrayHandlesMissingFields(): void
    {
        $event = TransactionScreened::fromArray([]);

        self::assertSame('', $event->eventId);
        self::assertSame('', $event->screenerIdentity);
        self::assertSame('', $event->transactionId);
        self::assertSame('', $event->customerPseudonym);
        self::assertSame('', $event->screeningResult);
        self::assertSame([], $event->matchedRules);
    }

    #[Test]
    public function schemaVersionIsOne(): void
    {
        self::assertSame(1, TransactionScreened::SCHEMA_VERSION);
    }
}
