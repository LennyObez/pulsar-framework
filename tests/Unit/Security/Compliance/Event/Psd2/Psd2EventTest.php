<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\Compliance\Event\Psd2;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\Compliance\Event\Psd2\CertificateValidated;
use Pulsar\Security\Compliance\Event\Psd2\ScaChallengeCreated;
use Pulsar\Security\Compliance\Event\Psd2\ScaChallengeVerified;
use Pulsar\Security\Compliance\Event\Psd2\TransactionRiskAssessed;

#[CoversClass(CertificateValidated::class)]
#[CoversClass(ScaChallengeCreated::class)]
#[CoversClass(ScaChallengeVerified::class)]
#[CoversClass(TransactionRiskAssessed::class)]
final class Psd2EventTest extends TestCase
{
    // ── CertificateValidated ────────────────────────────────────────

    #[Test]
    public function certificateValidatedStoresProperties(): void
    {
        $now = new DateTimeImmutable('2026-03-15T10:00:00+00:00');
        $event = new CertificateValidated(
            eventId: 'evt-cert',
            occurredAt: $now,
            correlationId: 'corr-cert',
            nonce: 'nonce-cert',
            serialNumber: 'SN-12345',
            certificateType: 'QWAC',
            authorizationNumber: 'PSDXX-XXXX-XXXX',
        );

        self::assertSame('evt-cert', $event->eventId);
        self::assertSame($now, $event->occurredAt);
        self::assertSame('corr-cert', $event->correlationId);
        self::assertSame('nonce-cert', $event->nonce);
        self::assertSame('SN-12345', $event->serialNumber);
        self::assertSame('QWAC', $event->certificateType);
        self::assertSame('PSDXX-XXXX-XXXX', $event->authorizationNumber);
    }

    #[Test]
    public function certificateValidatedRegulationAndEventType(): void
    {
        $event = $this->createCertificateEvent();

        self::assertSame('psd2', $event->regulation());
        self::assertSame('certificate_validated', $event->eventType());
    }

    #[Test]
    public function certificateValidatedRoundTrip(): void
    {
        $original = $this->createCertificateEvent();
        $array = $original->toArray();
        $restored = CertificateValidated::fromArray($array);

        self::assertSame($original->eventId, $restored->eventId);
        self::assertSame($original->serialNumber, $restored->serialNumber);
        self::assertSame($original->certificateType, $restored->certificateType);
        self::assertSame($original->authorizationNumber, $restored->authorizationNumber);
        self::assertSame(1, $array['schema_version']);
        self::assertSame('psd2', $array['regulation']);
        self::assertSame('certificate_validated', $array['event_type']);
    }

    #[Test]
    public function certificateValidatedFromArrayDefaultsOnMissingKeys(): void
    {
        $event = CertificateValidated::fromArray([]);

        self::assertSame('', $event->eventId);
        self::assertSame('', $event->serialNumber);
        self::assertSame('', $event->certificateType);
        self::assertSame('', $event->authorizationNumber);
    }

    // ── ScaChallengeCreated ─────────────────────────────────────────

    #[Test]
    public function scaChallengeCreatedStoresProperties(): void
    {
        $now = new DateTimeImmutable('2026-03-15T11:00:00+00:00');
        $event = new ScaChallengeCreated(
            eventId: 'evt-sca',
            occurredAt: $now,
            correlationId: 'corr-sca',
            nonce: 'nonce-sca',
            challengeId: 'chal-001',
            transactionId: 'txn-500',
            challengeType: 'otp_sms',
        );

        self::assertSame('chal-001', $event->challengeId);
        self::assertSame('txn-500', $event->transactionId);
        self::assertSame('otp_sms', $event->challengeType);
    }

    #[Test]
    public function scaChallengeCreatedRegulationAndEventType(): void
    {
        $event = $this->createChallengeCreatedEvent();

        self::assertSame('psd2', $event->regulation());
        self::assertSame('sca_challenge_created', $event->eventType());
    }

    #[Test]
    public function scaChallengeCreatedRoundTrip(): void
    {
        $original = $this->createChallengeCreatedEvent();
        $array = $original->toArray();
        $restored = ScaChallengeCreated::fromArray($array);

        self::assertSame($original->eventId, $restored->eventId);
        self::assertSame($original->challengeId, $restored->challengeId);
        self::assertSame($original->transactionId, $restored->transactionId);
        self::assertSame($original->challengeType, $restored->challengeType);
        self::assertSame(1, $array['schema_version']);
    }

    #[Test]
    public function scaChallengeCreatedFromArrayDefaultsOnMissingKeys(): void
    {
        $event = ScaChallengeCreated::fromArray([]);

        self::assertSame('', $event->challengeId);
        self::assertSame('', $event->transactionId);
        self::assertSame('', $event->challengeType);
    }

    // ── ScaChallengeVerified ────────────────────────────────────────

    #[Test]
    public function scaChallengeVerifiedStoresProperties(): void
    {
        $now = new DateTimeImmutable('2026-03-15T12:00:00+00:00');
        $event = new ScaChallengeVerified(
            eventId: 'evt-ver',
            occurredAt: $now,
            correlationId: 'corr-ver',
            nonce: 'nonce-ver',
            challengeId: 'chal-001',
            transactionId: 'txn-500',
        );

        self::assertSame('chal-001', $event->challengeId);
        self::assertSame('txn-500', $event->transactionId);
    }

    #[Test]
    public function scaChallengeVerifiedRegulationAndEventType(): void
    {
        $event = $this->createChallengeVerifiedEvent();

        self::assertSame('psd2', $event->regulation());
        self::assertSame('sca_challenge_verified', $event->eventType());
    }

    #[Test]
    public function scaChallengeVerifiedRoundTrip(): void
    {
        $original = $this->createChallengeVerifiedEvent();
        $array = $original->toArray();
        $restored = ScaChallengeVerified::fromArray($array);

        self::assertSame($original->eventId, $restored->eventId);
        self::assertSame($original->challengeId, $restored->challengeId);
        self::assertSame($original->transactionId, $restored->transactionId);
        self::assertSame(1, $array['schema_version']);
    }

    #[Test]
    public function scaChallengeVerifiedFromArrayDefaultsOnMissingKeys(): void
    {
        $event = ScaChallengeVerified::fromArray([]);

        self::assertSame('', $event->challengeId);
        self::assertSame('', $event->transactionId);
    }

    // ── TransactionRiskAssessed ─────────────────────────────────────

    #[Test]
    public function transactionRiskAssessedStoresProperties(): void
    {
        $now = new DateTimeImmutable('2026-03-15T13:00:00+00:00');
        $event = new TransactionRiskAssessed(
            eventId: 'evt-risk',
            occurredAt: $now,
            correlationId: 'corr-risk',
            nonce: 'nonce-risk',
            assessmentId: 'assess-100',
            transactionId: 'txn-700',
            riskLevel: 'low',
            exemption: 'tra',
            scaRequired: false,
        );

        self::assertSame('assess-100', $event->assessmentId);
        self::assertSame('txn-700', $event->transactionId);
        self::assertSame('low', $event->riskLevel);
        self::assertSame('tra', $event->exemption);
        self::assertFalse($event->scaRequired);
    }

    #[Test]
    public function transactionRiskAssessedScaRequiredTrue(): void
    {
        $event = new TransactionRiskAssessed(
            eventId: 'evt-sca-req',
            occurredAt: new DateTimeImmutable(),
            correlationId: 'corr-sca-req',
            nonce: 'nonce-sca-req',
            assessmentId: 'assess-200',
            transactionId: 'txn-800',
            riskLevel: 'high',
            exemption: 'none',
            scaRequired: true,
        );

        self::assertTrue($event->scaRequired);
    }

    #[Test]
    public function transactionRiskAssessedRegulationAndEventType(): void
    {
        $event = $this->createRiskEvent();

        self::assertSame('psd2', $event->regulation());
        self::assertSame('transaction_risk_assessed', $event->eventType());
    }

    #[Test]
    public function transactionRiskAssessedRoundTrip(): void
    {
        $original = $this->createRiskEvent();
        $array = $original->toArray();
        $restored = TransactionRiskAssessed::fromArray($array);

        self::assertSame($original->eventId, $restored->eventId);
        self::assertSame($original->assessmentId, $restored->assessmentId);
        self::assertSame($original->transactionId, $restored->transactionId);
        self::assertSame($original->riskLevel, $restored->riskLevel);
        self::assertSame($original->exemption, $restored->exemption);
        self::assertSame($original->scaRequired, $restored->scaRequired);
        self::assertSame(1, $array['schema_version']);
    }

    #[Test]
    public function transactionRiskAssessedFromArrayDefaultsOnMissingKeys(): void
    {
        $event = TransactionRiskAssessed::fromArray([]);

        self::assertSame('', $event->assessmentId);
        self::assertSame('', $event->transactionId);
        self::assertSame('', $event->riskLevel);
        self::assertSame('', $event->exemption);
        self::assertFalse($event->scaRequired);
    }

    #[Test]
    public function transactionRiskAssessedToArrayIncludesBoolField(): void
    {
        $event = new TransactionRiskAssessed(
            eventId: 'evt-bool',
            occurredAt: new DateTimeImmutable(),
            correlationId: 'corr-bool',
            nonce: 'nonce-bool',
            assessmentId: 'assess-bool',
            transactionId: 'txn-bool',
            riskLevel: 'medium',
            exemption: 'none',
            scaRequired: true,
        );

        $array = $event->toArray();

        self::assertTrue($array['sca_required']);
    }

    // ── Cross-event: all share regulation ───────────────────────────

    #[Test]
    public function allEventsSharePsd2Regulation(): void
    {
        self::assertSame('psd2', CertificateValidated::fromArray([])->regulation());
        self::assertSame('psd2', ScaChallengeCreated::fromArray([])->regulation());
        self::assertSame('psd2', ScaChallengeVerified::fromArray([])->regulation());
        self::assertSame('psd2', TransactionRiskAssessed::fromArray([])->regulation());
    }

    #[Test]
    #[DataProvider('eventTypeProvider')]
    public function eventTypesAreDistinct(string $class, string $expectedType): void
    {
        $event = match ($class) {
            CertificateValidated::class => CertificateValidated::fromArray([]),
            ScaChallengeCreated::class => ScaChallengeCreated::fromArray([]),
            ScaChallengeVerified::class => ScaChallengeVerified::fromArray([]),
            TransactionRiskAssessed::class => TransactionRiskAssessed::fromArray([]),
            default => self::fail("Unknown event class: {$class}"),
        };

        self::assertSame($expectedType, $event->eventType());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function eventTypeProvider(): iterable
    {
        yield 'CertificateValidated' => [CertificateValidated::class, 'certificate_validated'];
        yield 'ScaChallengeCreated' => [ScaChallengeCreated::class, 'sca_challenge_created'];
        yield 'ScaChallengeVerified' => [ScaChallengeVerified::class, 'sca_challenge_verified'];
        yield 'TransactionRiskAssessed' => [TransactionRiskAssessed::class, 'transaction_risk_assessed'];
    }

    // ── Helpers ─────────────────────────────────────────────────────

    private function createCertificateEvent(): CertificateValidated
    {
        return new CertificateValidated(
            eventId: 'evt-cert-h',
            occurredAt: new DateTimeImmutable('2026-01-01T00:00:00+00:00'),
            correlationId: 'corr-cert-h',
            nonce: 'nonce-cert-h',
            serialNumber: 'SN-99999',
            certificateType: 'QSealC',
            authorizationNumber: 'PSDFR-ACPR-12345',
        );
    }

    private function createChallengeCreatedEvent(): ScaChallengeCreated
    {
        return new ScaChallengeCreated(
            eventId: 'evt-chal-h',
            occurredAt: new DateTimeImmutable('2026-01-01T00:00:00+00:00'),
            correlationId: 'corr-chal-h',
            nonce: 'nonce-chal-h',
            challengeId: 'chal-helper',
            transactionId: 'txn-helper',
            challengeType: 'push_notification',
        );
    }

    private function createChallengeVerifiedEvent(): ScaChallengeVerified
    {
        return new ScaChallengeVerified(
            eventId: 'evt-ver-h',
            occurredAt: new DateTimeImmutable('2026-01-01T00:00:00+00:00'),
            correlationId: 'corr-ver-h',
            nonce: 'nonce-ver-h',
            challengeId: 'chal-verified',
            transactionId: 'txn-verified',
        );
    }

    private function createRiskEvent(): TransactionRiskAssessed
    {
        return new TransactionRiskAssessed(
            eventId: 'evt-risk-h',
            occurredAt: new DateTimeImmutable('2026-01-01T00:00:00+00:00'),
            correlationId: 'corr-risk-h',
            nonce: 'nonce-risk-h',
            assessmentId: 'assess-helper',
            transactionId: 'txn-risk-helper',
            riskLevel: 'medium',
            exemption: 'none',
            scaRequired: true,
        );
    }
}
