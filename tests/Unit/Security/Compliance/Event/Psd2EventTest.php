<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\Compliance\Event;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\Compliance\Event\Psd2\CertificateValidated;
use Pulsar\Security\Compliance\Event\Psd2\ScaChallengeCreated;
use Pulsar\Security\Compliance\Event\Psd2\ScaChallengeVerified;
use Pulsar\Security\Compliance\Event\Psd2\TransactionRiskAssessed;

#[CoversClass(ScaChallengeCreated::class)]
#[CoversClass(ScaChallengeVerified::class)]
#[CoversClass(TransactionRiskAssessed::class)]
#[CoversClass(CertificateValidated::class)]
final class Psd2EventTest extends TestCase
{
    private const string TS = '2026-03-15T10:00:00.000000+00:00';

    public function testScaChallengeCreatedRoundTrip(): void
    {
        $event = new ScaChallengeCreated(
            eventId: 'evt-1',
            occurredAt: new DateTimeImmutable(self::TS),
            correlationId: 'corr-1',
            nonce: 'n1',
            challengeId: 'chal-1',
            transactionId: 'txn-100',
            challengeType: 'sms_otp',
        );

        self::assertSame('psd2', $event->regulation());
        self::assertSame('sca_challenge_created', $event->eventType());
        self::assertSame(1, ScaChallengeCreated::SCHEMA_VERSION);

        $restored = ScaChallengeCreated::fromArray($event->toArray());
        self::assertSame('chal-1', $restored->challengeId);
        self::assertSame('txn-100', $restored->transactionId);
        self::assertSame('sms_otp', $restored->challengeType);
        self::assertSame('corr-1', $restored->correlationId);
        self::assertSame('n1', $restored->nonce);
    }

    public function testScaChallengeVerifiedRoundTrip(): void
    {
        $event = new ScaChallengeVerified(
            eventId: 'evt-2',
            occurredAt: new DateTimeImmutable(self::TS),
            correlationId: 'corr-2',
            nonce: 'n2',
            challengeId: 'chal-2',
            transactionId: 'txn-200',
        );

        self::assertSame('psd2', $event->regulation());
        self::assertSame('sca_challenge_verified', $event->eventType());

        $restored = ScaChallengeVerified::fromArray($event->toArray());
        self::assertSame('chal-2', $restored->challengeId);
        self::assertSame('txn-200', $restored->transactionId);
    }

    public function testTransactionRiskAssessedRoundTrip(): void
    {
        $event = new TransactionRiskAssessed(
            eventId: 'evt-3',
            occurredAt: new DateTimeImmutable(self::TS),
            correlationId: 'corr-3',
            nonce: 'n3',
            assessmentId: 'assess-1',
            transactionId: 'txn-300',
            riskLevel: 'low',
            exemption: 'low_value',
            scaRequired: false,
        );

        self::assertSame('psd2', $event->regulation());
        self::assertSame('transaction_risk_assessed', $event->eventType());

        $restored = TransactionRiskAssessed::fromArray($event->toArray());
        self::assertSame('assess-1', $restored->assessmentId);
        self::assertSame('low', $restored->riskLevel);
        self::assertSame('low_value', $restored->exemption);
        self::assertFalse($restored->scaRequired);
    }

    public function testTransactionRiskAssessedScaRequired(): void
    {
        $event = new TransactionRiskAssessed(
            eventId: 'evt-4',
            occurredAt: new DateTimeImmutable(self::TS),
            correlationId: 'corr-4',
            nonce: 'n4',
            assessmentId: 'assess-2',
            transactionId: 'txn-400',
            riskLevel: 'high',
            exemption: '',
            scaRequired: true,
        );

        $restored = TransactionRiskAssessed::fromArray($event->toArray());
        self::assertTrue($restored->scaRequired);
    }

    public function testCertificateValidatedRoundTrip(): void
    {
        $event = new CertificateValidated(
            eventId: 'evt-5',
            occurredAt: new DateTimeImmutable(self::TS),
            correlationId: 'corr-5',
            nonce: 'n5',
            serialNumber: 'SN-12345',
            certificateType: 'QWAC',
            authorizationNumber: 'AUTH-001',
        );

        self::assertSame('psd2', $event->regulation());
        self::assertSame('certificate_validated', $event->eventType());

        $restored = CertificateValidated::fromArray($event->toArray());
        self::assertSame('SN-12345', $restored->serialNumber);
        self::assertSame('QWAC', $restored->certificateType);
        self::assertSame('AUTH-001', $restored->authorizationNumber);
    }

    public function testFromArrayWithMissingDataUsesDefaults(): void
    {
        $event = ScaChallengeCreated::fromArray([]);

        self::assertSame('', $event->eventId);
        self::assertSame('', $event->challengeId);
        self::assertSame('', $event->correlationId);
    }
}
