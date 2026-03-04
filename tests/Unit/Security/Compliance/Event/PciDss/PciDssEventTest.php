<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\Compliance\Event\PciDss;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\Compliance\Event\PciDss\AccessControlChanged;
use Pulsar\Security\Compliance\Event\PciDss\CardDataAccessed;
use Pulsar\Security\Compliance\Event\PciDss\KeyRotated;
use Pulsar\Security\Compliance\Event\PciDss\PenetrationTestCompleted;
use Pulsar\Security\Compliance\Event\PciDss\VulnerabilityFound;

#[CoversClass(AccessControlChanged::class)]
#[CoversClass(CardDataAccessed::class)]
#[CoversClass(KeyRotated::class)]
#[CoversClass(PenetrationTestCompleted::class)]
#[CoversClass(VulnerabilityFound::class)]
final class PciDssEventTest extends TestCase
{
    // ── AccessControlChanged ────────────────────────────────────────

    #[Test]
    public function accessControlChangedRegulationReturnsPciDss(): void
    {
        $event = new AccessControlChanged(
            eventId: 'evt-pci-ac-001',
            occurredAt: new DateTimeImmutable('2026-03-05T10:00:00+00:00'),
            correlationId: 'corr-1',
            nonce: 'nonce-1',
            operatorIdentity: 'admin@company.com',
            targetIdentity: 'user@company.com',
            changeType: 'grant',
            permission: 'cde_read',
            reason: 'role_promotion',
        );

        self::assertSame('pci_dss', $event->regulation());
        self::assertSame('access_control_changed', $event->eventType());
    }

    #[Test]
    public function accessControlChangedToArrayIncludesAllFields(): void
    {
        $event = new AccessControlChanged(
            eventId: 'evt-pci-ac-001',
            occurredAt: new DateTimeImmutable('2026-03-05T10:00:00+00:00'),
            correlationId: 'corr-1',
            nonce: 'nonce-1',
            operatorIdentity: 'admin@company.com',
            targetIdentity: 'user@company.com',
            changeType: 'grant',
            permission: 'cde_read',
            reason: 'role_promotion',
        );

        $array = $event->toArray();
        self::assertSame('admin@company.com', $array['operator_identity']);
        self::assertSame('user@company.com', $array['target_identity']);
        self::assertSame('grant', $array['change_type']);
        self::assertSame('cde_read', $array['permission']);
        self::assertSame('role_promotion', $array['reason']);
        self::assertSame(1, $array['schema_version']);
    }

    #[Test]
    public function accessControlChangedRoundTrips(): void
    {
        $original = new AccessControlChanged(
            eventId: 'evt-pci-ac-001',
            occurredAt: new DateTimeImmutable('2026-03-05T10:00:00+00:00'),
            correlationId: 'corr-1',
            nonce: 'nonce-1',
            operatorIdentity: 'admin@company.com',
            targetIdentity: 'user@company.com',
            changeType: 'revoke',
            permission: 'cde_write',
            reason: 'termination',
        );

        $restored = AccessControlChanged::fromArray($original->toArray());
        self::assertSame($original->operatorIdentity, $restored->operatorIdentity);
        self::assertSame($original->changeType, $restored->changeType);
        self::assertSame($original->permission, $restored->permission);
    }

    #[Test]
    public function accessControlChangedFromArrayHandlesMissingFields(): void
    {
        $event = AccessControlChanged::fromArray([]);
        self::assertSame('', $event->operatorIdentity);
        self::assertSame('', $event->reason);
    }

    // ── CardDataAccessed ────────────────────────────────────────────

    #[Test]
    public function cardDataAccessedRegulationReturnsPciDss(): void
    {
        $event = new CardDataAccessed(
            eventId: 'evt-pci-cd-001',
            occurredAt: new DateTimeImmutable('2026-03-05T11:00:00+00:00'),
            correlationId: 'corr-2',
            nonce: 'nonce-2',
            accessorIdentity: 'support@company.com',
            dataType: 'primary_account_number',
            purpose: 'customer_dispute_resolution',
            maskedIdentifier: '****-****-****-1234',
        );

        self::assertSame('pci_dss', $event->regulation());
        self::assertSame('card_data_accessed', $event->eventType());
    }

    #[Test]
    public function cardDataAccessedToArrayIncludesAllFields(): void
    {
        $event = new CardDataAccessed(
            eventId: 'evt-pci-cd-001',
            occurredAt: new DateTimeImmutable('2026-03-05T11:00:00+00:00'),
            correlationId: 'corr-2',
            nonce: 'nonce-2',
            accessorIdentity: 'support@company.com',
            dataType: 'primary_account_number',
            purpose: 'customer_dispute_resolution',
            maskedIdentifier: '****-****-****-1234',
        );

        $array = $event->toArray();
        self::assertSame('support@company.com', $array['accessor_identity']);
        self::assertSame('primary_account_number', $array['data_type']);
        self::assertSame('customer_dispute_resolution', $array['purpose']);
        self::assertSame('****-****-****-1234', $array['masked_identifier']);
        self::assertSame(1, $array['schema_version']);
    }

    #[Test]
    public function cardDataAccessedRoundTrips(): void
    {
        $original = new CardDataAccessed(
            eventId: 'evt-pci-cd-001',
            occurredAt: new DateTimeImmutable('2026-03-05T11:00:00+00:00'),
            correlationId: 'corr-2',
            nonce: 'nonce-2',
            accessorIdentity: 'support@company.com',
            dataType: 'pan',
            purpose: 'refund',
            maskedIdentifier: '****1234',
        );

        $restored = CardDataAccessed::fromArray($original->toArray());
        self::assertSame($original->accessorIdentity, $restored->accessorIdentity);
        self::assertSame($original->maskedIdentifier, $restored->maskedIdentifier);
    }

    #[Test]
    public function cardDataAccessedFromArrayHandlesMissingFields(): void
    {
        $event = CardDataAccessed::fromArray([]);
        self::assertSame('', $event->accessorIdentity);
        self::assertSame('', $event->maskedIdentifier);
    }

    // ── KeyRotated ──────────────────────────────────────────────────

    #[Test]
    public function keyRotatedRegulationReturnsPciDss(): void
    {
        $event = new KeyRotated(
            eventId: 'evt-pci-kr-001',
            occurredAt: new DateTimeImmutable('2026-03-05T12:00:00+00:00'),
            correlationId: 'corr-3',
            nonce: 'nonce-3',
            operatorIdentity: 'security@company.com',
            keyPurpose: 'card_data_encryption',
            previousKeyId: 'key-old-001',
            newKeyId: 'key-new-002',
            rotationReason: 'scheduled_rotation',
        );

        self::assertSame('pci_dss', $event->regulation());
        self::assertSame('key_rotated', $event->eventType());
    }

    #[Test]
    public function keyRotatedToArrayIncludesAllFields(): void
    {
        $event = new KeyRotated(
            eventId: 'evt-pci-kr-001',
            occurredAt: new DateTimeImmutable('2026-03-05T12:00:00+00:00'),
            correlationId: 'corr-3',
            nonce: 'nonce-3',
            operatorIdentity: 'security@company.com',
            keyPurpose: 'card_data_encryption',
            previousKeyId: 'key-old-001',
            newKeyId: 'key-new-002',
            rotationReason: 'scheduled_rotation',
        );

        $array = $event->toArray();
        self::assertSame('security@company.com', $array['operator_identity']);
        self::assertSame('card_data_encryption', $array['key_purpose']);
        self::assertSame('key-old-001', $array['previous_key_id']);
        self::assertSame('key-new-002', $array['new_key_id']);
        self::assertSame('scheduled_rotation', $array['rotation_reason']);
        self::assertSame(1, $array['schema_version']);
    }

    #[Test]
    public function keyRotatedRoundTrips(): void
    {
        $original = new KeyRotated(
            eventId: 'evt-pci-kr-001',
            occurredAt: new DateTimeImmutable('2026-03-05T12:00:00+00:00'),
            correlationId: 'corr-3',
            nonce: 'nonce-3',
            operatorIdentity: 'sec@co.com',
            keyPurpose: 'dek',
            previousKeyId: 'k1',
            newKeyId: 'k2',
            rotationReason: 'compromise_suspected',
        );

        $restored = KeyRotated::fromArray($original->toArray());
        self::assertSame($original->previousKeyId, $restored->previousKeyId);
        self::assertSame($original->newKeyId, $restored->newKeyId);
        self::assertSame($original->rotationReason, $restored->rotationReason);
    }

    #[Test]
    public function keyRotatedFromArrayHandlesMissingFields(): void
    {
        $event = KeyRotated::fromArray([]);
        self::assertSame('', $event->previousKeyId);
        self::assertSame('', $event->rotationReason);
    }

    // ── PenetrationTestCompleted ────────────────────────────────────

    #[Test]
    public function penetrationTestCompletedRegulationReturnsPciDss(): void
    {
        $event = new PenetrationTestCompleted(
            eventId: 'evt-pci-pt-001',
            occurredAt: new DateTimeImmutable('2026-03-05T13:00:00+00:00'),
            correlationId: 'corr-4',
            nonce: 'nonce-4',
            testerIdentity: 'pentest-vendor@external.com',
            scope: 'external_network_and_web_app',
            findingsCount: 7,
            criticalFindings: 1,
            testDate: new DateTimeImmutable('2026-03-01T00:00:00+00:00'),
        );

        self::assertSame('pci_dss', $event->regulation());
        self::assertSame('penetration_test_completed', $event->eventType());
    }

    #[Test]
    public function penetrationTestCompletedToArrayIncludesAllFields(): void
    {
        $event = new PenetrationTestCompleted(
            eventId: 'evt-pci-pt-001',
            occurredAt: new DateTimeImmutable('2026-03-05T13:00:00+00:00'),
            correlationId: 'corr-4',
            nonce: 'nonce-4',
            testerIdentity: 'vendor@external.com',
            scope: 'cde',
            findingsCount: 3,
            criticalFindings: 0,
            testDate: new DateTimeImmutable('2026-03-01T00:00:00+00:00'),
        );

        $array = $event->toArray();
        self::assertSame('vendor@external.com', $array['tester_identity']);
        self::assertSame('cde', $array['scope']);
        self::assertSame(3, $array['findings_count']);
        self::assertSame(0, $array['critical_findings']);
        self::assertArrayHasKey('test_date', $array);
        self::assertSame(1, $array['schema_version']);
    }

    #[Test]
    public function penetrationTestCompletedRoundTrips(): void
    {
        $original = new PenetrationTestCompleted(
            eventId: 'evt-pci-pt-001',
            occurredAt: new DateTimeImmutable('2026-03-05T13:00:00+00:00'),
            correlationId: 'corr-4',
            nonce: 'nonce-4',
            testerIdentity: 'tester@co.com',
            scope: 'full',
            findingsCount: 5,
            criticalFindings: 2,
            testDate: new DateTimeImmutable('2026-03-01T00:00:00+00:00'),
        );

        $restored = PenetrationTestCompleted::fromArray($original->toArray());
        self::assertSame($original->findingsCount, $restored->findingsCount);
        self::assertSame($original->criticalFindings, $restored->criticalFindings);
        self::assertSame($original->scope, $restored->scope);
    }

    #[Test]
    public function penetrationTestCompletedFromArrayHandlesMissingFields(): void
    {
        $event = PenetrationTestCompleted::fromArray([]);
        self::assertSame('', $event->testerIdentity);
        self::assertSame(0, $event->findingsCount);
        self::assertSame(0, $event->criticalFindings);
    }

    // ── VulnerabilityFound ──────────────────────────────────────────

    #[Test]
    public function vulnerabilityFoundRegulationReturnsPciDss(): void
    {
        $event = new VulnerabilityFound(
            eventId: 'evt-pci-vf-001',
            occurredAt: new DateTimeImmutable('2026-03-05T14:00:00+00:00'),
            correlationId: 'corr-5',
            nonce: 'nonce-5',
            reporterIdentity: 'scanner@company.com',
            severity: 'critical',
            cveId: 'CVE-2026-12345',
            affectedComponent: 'payment-gateway-lib',
            remediationDeadline: new DateTimeImmutable('2026-03-15T00:00:00+00:00'),
        );

        self::assertSame('pci_dss', $event->regulation());
        self::assertSame('vulnerability_found', $event->eventType());
    }

    #[Test]
    public function vulnerabilityFoundToArrayIncludesAllFields(): void
    {
        $event = new VulnerabilityFound(
            eventId: 'evt-pci-vf-001',
            occurredAt: new DateTimeImmutable('2026-03-05T14:00:00+00:00'),
            correlationId: 'corr-5',
            nonce: 'nonce-5',
            reporterIdentity: 'scanner@co.com',
            severity: 'high',
            cveId: 'CVE-2026-99999',
            affectedComponent: 'api-gateway',
            remediationDeadline: new DateTimeImmutable('2026-04-01T00:00:00+00:00'),
        );

        $array = $event->toArray();
        self::assertSame('scanner@co.com', $array['reporter_identity']);
        self::assertSame('high', $array['severity']);
        self::assertSame('CVE-2026-99999', $array['cve_id']);
        self::assertSame('api-gateway', $array['affected_component']);
        self::assertArrayHasKey('remediation_deadline', $array);
        self::assertSame(1, $array['schema_version']);
    }

    #[Test]
    public function vulnerabilityFoundRoundTrips(): void
    {
        $original = new VulnerabilityFound(
            eventId: 'evt-pci-vf-001',
            occurredAt: new DateTimeImmutable('2026-03-05T14:00:00+00:00'),
            correlationId: 'corr-5',
            nonce: 'nonce-5',
            reporterIdentity: 'scanner@co.com',
            severity: 'medium',
            cveId: 'CVE-2026-11111',
            affectedComponent: 'tls-lib',
            remediationDeadline: new DateTimeImmutable('2026-05-01T00:00:00+00:00'),
        );

        $restored = VulnerabilityFound::fromArray($original->toArray());
        self::assertSame($original->severity, $restored->severity);
        self::assertSame($original->cveId, $restored->cveId);
        self::assertSame($original->affectedComponent, $restored->affectedComponent);
    }

    #[Test]
    public function vulnerabilityFoundFromArrayHandlesMissingFields(): void
    {
        $event = VulnerabilityFound::fromArray([]);
        self::assertSame('', $event->reporterIdentity);
        self::assertSame('', $event->cveId);
    }
}
