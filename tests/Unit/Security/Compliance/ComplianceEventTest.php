<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\Compliance;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\Compliance\ComplianceEvent;
use Pulsar\Security\Compliance\DataClassification;
use Pulsar\Security\Compliance\Event\Aml\CustomerVerified;
use Pulsar\Security\Compliance\Event\Aml\SanctionsChecked;
use Pulsar\Security\Compliance\Event\Aml\SuspiciousActivityDetected;
use Pulsar\Security\Compliance\Event\Aml\TransactionScreened;
use Pulsar\Security\Compliance\Event\Dora\IctIncidentDetected;
use Pulsar\Security\Compliance\Event\Dora\RecoveryInitiated;
use Pulsar\Security\Compliance\Event\Dora\ResilienceTestCompleted;
use Pulsar\Security\Compliance\Event\Dora\ThirdPartyRiskAssessed;
use Pulsar\Security\Compliance\Event\Gdpr\BreachDetected;
use Pulsar\Security\Compliance\Event\Gdpr\BreachNotified;
use Pulsar\Security\Compliance\Event\Gdpr\ConsentGranted;
use Pulsar\Security\Compliance\Event\Gdpr\ConsentRevoked;
use Pulsar\Security\Compliance\Event\Gdpr\DataAccessRequested;
use Pulsar\Security\Compliance\Event\Gdpr\DataDeletionRequested;
use Pulsar\Security\Compliance\Event\Gdpr\DataPortabilityRequested;
use Pulsar\Security\Compliance\Event\Gdpr\DpiaCompleted;
use Pulsar\Security\Compliance\Event\Hipaa\AuditReviewCompleted;
use Pulsar\Security\Compliance\Event\Hipaa\BreachNotification;
use Pulsar\Security\Compliance\Event\Hipaa\PhiAccessed;
use Pulsar\Security\Compliance\Event\Hipaa\PhiDisclosed;
use Pulsar\Security\Compliance\Event\Hipaa\PhiModified;
use Pulsar\Security\Compliance\Event\Hipaa\SecurityIncident;
use Pulsar\Security\Compliance\Event\PciDss\AccessControlChanged as PciAccessControlChanged;
use Pulsar\Security\Compliance\Event\PciDss\CardDataAccessed;
use Pulsar\Security\Compliance\Event\PciDss\KeyRotated;
use Pulsar\Security\Compliance\Event\PciDss\PenetrationTestCompleted;
use Pulsar\Security\Compliance\Event\PciDss\VulnerabilityFound;
use Pulsar\Security\Compliance\Event\Sox\AccessControlChanged as SoxAccessControlChanged;
use Pulsar\Security\Compliance\Event\Sox\AuditTrailVerified;
use Pulsar\Security\Compliance\Event\Sox\ControlTestCompleted;
use Pulsar\Security\Compliance\Event\Sox\FinancialDataModified;
use Pulsar\Security\Compliance\Exception\ComplianceException;

#[CoversClass(ComplianceEvent::class)]
#[CoversClass(ComplianceException::class)]
#[CoversClass(ConsentGranted::class)]
#[CoversClass(ConsentRevoked::class)]
#[CoversClass(DataAccessRequested::class)]
#[CoversClass(DataDeletionRequested::class)]
#[CoversClass(DataPortabilityRequested::class)]
#[CoversClass(BreachDetected::class)]
#[CoversClass(BreachNotified::class)]
#[CoversClass(DpiaCompleted::class)]
#[CoversClass(PhiAccessed::class)]
#[CoversClass(PhiModified::class)]
#[CoversClass(PhiDisclosed::class)]
#[CoversClass(BreachNotification::class)]
#[CoversClass(SecurityIncident::class)]
#[CoversClass(AuditReviewCompleted::class)]
#[CoversClass(CardDataAccessed::class)]
#[CoversClass(KeyRotated::class)]
#[CoversClass(PciAccessControlChanged::class)]
#[CoversClass(PenetrationTestCompleted::class)]
#[CoversClass(VulnerabilityFound::class)]
#[CoversClass(FinancialDataModified::class)]
#[CoversClass(SoxAccessControlChanged::class)]
#[CoversClass(AuditTrailVerified::class)]
#[CoversClass(ControlTestCompleted::class)]
#[CoversClass(IctIncidentDetected::class)]
#[CoversClass(ResilienceTestCompleted::class)]
#[CoversClass(ThirdPartyRiskAssessed::class)]
#[CoversClass(RecoveryInitiated::class)]
#[CoversClass(CustomerVerified::class)]
#[CoversClass(SuspiciousActivityDetected::class)]
#[CoversClass(TransactionScreened::class)]
#[CoversClass(SanctionsChecked::class)]
final class ComplianceEventTest extends TestCase
{
    private const string TIMESTAMP = '2025-06-15T10:30:00.000000+00:00';

    #[Test]
    public function dataClassificationHasFourCases(): void
    {
        self::assertSame('public', DataClassification::Public->value);
        self::assertSame('internal', DataClassification::Internal->value);
        self::assertSame('confidential', DataClassification::Confidential->value);
        self::assertSame('restricted', DataClassification::Restricted->value);
        self::assertCount(4, DataClassification::cases());
    }

    #[Test]
    public function complianceExceptionStaticFactories(): void
    {
        self::assertStringContainsString('field_name', ComplianceException::missingClassification('field_name')->getMessage());
        self::assertStringContainsString('1', ComplianceException::invalidSchemaVersion(1, 2)->getMessage());
        self::assertStringContainsString('reason', ComplianceException::snapshotCaptureRefused('reason')->getMessage());
        self::assertStringContainsString('identifier', ComplianceException::pseudonymNotFound()->getMessage());
        self::assertStringContainsString('reason', ComplianceException::retentionPolicyViolation('reason')->getMessage());
        self::assertStringContainsString('reason', ComplianceException::evidenceExportFailed('reason')->getMessage());
    }

    #[Test]
    public function gdprConsentGrantedRoundTrip(): void
    {
        $event = new ConsentGranted(
            eventId: 'evt-1',
            occurredAt: new DateTimeImmutable(self::TIMESTAMP),
            correlationId: 'corr-1',
            nonce: 'nonce1',
            subjectId: 'subject-1',
            purpose: 'marketing',
            legalBasis: 'consent',
            consentScope: 'email_campaigns',
            expiresAt: new DateTimeImmutable('2026-01-01T00:00:00.000000+00:00'),
        );

        self::assertSame('gdpr', $event->regulation());
        self::assertSame('consent_granted', $event->eventType());
        self::assertSame(1, ConsentGranted::SCHEMA_VERSION);

        $array = $event->toArray();
        $restored = ConsentGranted::fromArray($array);

        self::assertSame('evt-1', $restored->eventId);
        self::assertSame('subject-1', $restored->subjectId);
        self::assertSame('marketing', $restored->purpose);
        self::assertSame('consent', $restored->legalBasis);
        self::assertSame('nonce1', $restored->nonce);
        self::assertNotNull($restored->expiresAt);
    }

    #[Test]
    public function gdprConsentRevokedRoundTrip(): void
    {
        $event = new ConsentRevoked(
            eventId: 'evt-2',
            occurredAt: new DateTimeImmutable(self::TIMESTAMP),
            correlationId: 'corr-2',
            nonce: 'nonce2',
            subjectId: 'subject-2',
            purpose: 'analytics',
            revocationReason: 'user_request',
        );

        self::assertSame('gdpr', $event->regulation());
        self::assertSame('consent_revoked', $event->eventType());

        $restored = ConsentRevoked::fromArray($event->toArray());
        self::assertSame('subject-2', $restored->subjectId);
        self::assertSame('user_request', $restored->revocationReason);
    }

    #[Test]
    public function gdprDataAccessRequestedRoundTrip(): void
    {
        $event = new DataAccessRequested(
            eventId: 'evt-3',
            occurredAt: new DateTimeImmutable(self::TIMESTAMP),
            correlationId: 'corr-3',
            nonce: 'nonce3',
            subjectId: 'subject-3',
            requesterIdentity: 'requester-1',
            dataCategories: ['personal', 'financial'],
            legalBasis: 'subject_request',
        );

        self::assertSame('gdpr', $event->regulation());
        self::assertSame('data_access_requested', $event->eventType());

        $restored = DataAccessRequested::fromArray($event->toArray());
        self::assertSame(['personal', 'financial'], $restored->dataCategories);
    }

    #[Test]
    public function gdprDataDeletionRequestedRoundTrip(): void
    {
        $event = new DataDeletionRequested(
            eventId: 'evt-4',
            occurredAt: new DateTimeImmutable(self::TIMESTAMP),
            correlationId: 'corr-4',
            nonce: 'nonce4',
            subjectId: 'subject-4',
            requesterIdentity: 'requester-2',
            deletionScope: 'all_personal_data',
            legalBasis: 'right_to_erasure',
        );

        self::assertSame('gdpr', $event->regulation());
        self::assertSame('data_deletion_requested', $event->eventType());

        $restored = DataDeletionRequested::fromArray($event->toArray());
        self::assertSame('all_personal_data', $restored->deletionScope);
    }

    #[Test]
    public function gdprDataPortabilityRequestedRoundTrip(): void
    {
        $event = new DataPortabilityRequested(
            eventId: 'evt-5',
            occurredAt: new DateTimeImmutable(self::TIMESTAMP),
            correlationId: 'corr-5',
            nonce: 'nonce5',
            subjectId: 'subject-5',
            requesterIdentity: 'requester-3',
            format: 'json',
            dataCategories: ['profile', 'activity'],
        );

        self::assertSame('gdpr', $event->regulation());
        self::assertSame('data_portability_requested', $event->eventType());

        $restored = DataPortabilityRequested::fromArray($event->toArray());
        self::assertSame('json', $restored->format);
        self::assertSame(['profile', 'activity'], $restored->dataCategories);
    }

    #[Test]
    public function gdprBreachDetectedRoundTrip(): void
    {
        $event = new BreachDetected(
            eventId: 'evt-6',
            occurredAt: new DateTimeImmutable(self::TIMESTAMP),
            correlationId: 'corr-6',
            nonce: 'nonce6',
            detectedBy: 'siem-system',
            affectedSubjectCount: 1500,
            dataCategories: ['email', 'name'],
            severity: 'high',
            description: 'Unauthorized access to customer database',
        );

        self::assertSame('gdpr', $event->regulation());
        self::assertSame('breach_detected', $event->eventType());

        $restored = BreachDetected::fromArray($event->toArray());
        self::assertSame(1500, $restored->affectedSubjectCount);
        self::assertSame('high', $restored->severity);
    }

    #[Test]
    public function gdprBreachNotifiedRoundTrip(): void
    {
        $event = new BreachNotified(
            eventId: 'evt-7',
            occurredAt: new DateTimeImmutable(self::TIMESTAMP),
            correlationId: 'corr-7',
            nonce: 'nonce7',
            authority: 'ICO',
            notifiedAt: new DateTimeImmutable(self::TIMESTAMP),
            breachEventId: 'breach-1',
            responseDeadline: new DateTimeImmutable('2025-06-18T10:30:00.000000+00:00'),
        );

        self::assertSame('gdpr', $event->regulation());
        self::assertSame('breach_notified', $event->eventType());

        $restored = BreachNotified::fromArray($event->toArray());
        self::assertSame('ICO', $restored->authority);
        self::assertSame('breach-1', $restored->breachEventId);
    }

    #[Test]
    public function gdprDpiaCompletedRoundTrip(): void
    {
        $event = new DpiaCompleted(
            eventId: 'evt-8',
            occurredAt: new DateTimeImmutable(self::TIMESTAMP),
            correlationId: 'corr-8',
            nonce: 'nonce8',
            assessorIdentity: 'dpo-1',
            processingActivity: 'customer_profiling',
            riskLevel: 'medium',
            mitigations: ['data_minimization', 'encryption'],
        );

        self::assertSame('gdpr', $event->regulation());
        self::assertSame('dpia_completed', $event->eventType());

        $restored = DpiaCompleted::fromArray($event->toArray());
        self::assertSame(['data_minimization', 'encryption'], $restored->mitigations);
    }

    #[Test]
    public function hipaaPhiAccessedRoundTrip(): void
    {
        $event = new PhiAccessed(
            eventId: 'evt-9',
            occurredAt: new DateTimeImmutable(self::TIMESTAMP),
            correlationId: 'corr-9',
            nonce: 'nonce9',
            accessorIdentity: 'doctor-1',
            patientPseudonym: 'pseudo-p1',
            phiCategories: ['diagnosis', 'medication'],
            purpose: 'treatment',
            accessMethod: 'ehr_portal',
        );

        self::assertSame('hipaa', $event->regulation());
        self::assertSame('phi_accessed', $event->eventType());
        self::assertSame(1, PhiAccessed::SCHEMA_VERSION);

        $restored = PhiAccessed::fromArray($event->toArray());
        self::assertSame(['diagnosis', 'medication'], $restored->phiCategories);
        self::assertSame('treatment', $restored->purpose);
    }

    #[Test]
    public function hipaaPhiModifiedRoundTrip(): void
    {
        $event = new PhiModified(
            eventId: 'evt-10',
            occurredAt: new DateTimeImmutable(self::TIMESTAMP),
            correlationId: 'corr-10',
            nonce: 'nonce10',
            modifierIdentity: 'nurse-1',
            patientPseudonym: 'pseudo-p2',
            phiCategories: ['vital_signs'],
            modificationType: 'update',
            reason: 'routine_checkup',
        );

        self::assertSame('hipaa', $event->regulation());
        self::assertSame('phi_modified', $event->eventType());

        $restored = PhiModified::fromArray($event->toArray());
        self::assertSame('update', $restored->modificationType);
    }

    #[Test]
    public function hipaaPhiDisclosedRoundTrip(): void
    {
        $event = new PhiDisclosed(
            eventId: 'evt-11',
            occurredAt: new DateTimeImmutable(self::TIMESTAMP),
            correlationId: 'corr-11',
            nonce: 'nonce11',
            discloserIdentity: 'hospital-1',
            recipientIdentity: 'insurance-1',
            patientPseudonym: 'pseudo-p3',
            phiCategories: ['billing'],
            legalBasis: 'payment',
        );

        self::assertSame('hipaa', $event->regulation());
        self::assertSame('phi_disclosed', $event->eventType());

        $restored = PhiDisclosed::fromArray($event->toArray());
        self::assertSame('insurance-1', $restored->recipientIdentity);
    }

    #[Test]
    public function hipaaBreachNotificationRoundTrip(): void
    {
        $event = new BreachNotification(
            eventId: 'evt-12',
            occurredAt: new DateTimeImmutable(self::TIMESTAMP),
            correlationId: 'corr-12',
            nonce: 'nonce12',
            reporterIdentity: 'compliance-officer',
            affectedCount: 500,
            phiCategories: ['ssn', 'dob'],
            discoveryDate: new DateTimeImmutable(self::TIMESTAMP),
            notificationDeadline: new DateTimeImmutable('2025-08-13T10:30:00.000000+00:00'),
        );

        self::assertSame('hipaa', $event->regulation());
        self::assertSame('breach_notification', $event->eventType());

        $restored = BreachNotification::fromArray($event->toArray());
        self::assertSame(500, $restored->affectedCount);
    }

    #[Test]
    public function hipaaSecurityIncidentRoundTrip(): void
    {
        $event = new SecurityIncident(
            eventId: 'evt-13',
            occurredAt: new DateTimeImmutable(self::TIMESTAMP),
            correlationId: 'corr-13',
            nonce: 'nonce13',
            reporterIdentity: 'soc-analyst',
            incidentType: 'unauthorized_access',
            severity: 'critical',
            description: 'Unauthorized PHI access detected',
            containmentActions: ['account_lockout', 'network_isolation'],
        );

        self::assertSame('hipaa', $event->regulation());
        self::assertSame('security_incident', $event->eventType());

        $restored = SecurityIncident::fromArray($event->toArray());
        self::assertSame(['account_lockout', 'network_isolation'], $restored->containmentActions);
    }

    #[Test]
    public function hipaaAuditReviewCompletedRoundTrip(): void
    {
        $event = new AuditReviewCompleted(
            eventId: 'evt-14',
            occurredAt: new DateTimeImmutable(self::TIMESTAMP),
            correlationId: 'corr-14',
            nonce: 'nonce14',
            reviewerIdentity: 'auditor-1',
            reviewPeriod: '2025-Q1',
            findingsCount: 12,
            criticalFindings: 2,
        );

        self::assertSame('hipaa', $event->regulation());
        self::assertSame('audit_review_completed', $event->eventType());

        $restored = AuditReviewCompleted::fromArray($event->toArray());
        self::assertSame(12, $restored->findingsCount);
        self::assertSame(2, $restored->criticalFindings);
    }

    #[Test]
    public function pciDssCardDataAccessedRoundTrip(): void
    {
        $event = new CardDataAccessed(
            eventId: 'evt-15',
            occurredAt: new DateTimeImmutable(self::TIMESTAMP),
            correlationId: 'corr-15',
            nonce: 'nonce15',
            accessorIdentity: 'payment-service',
            dataType: 'PAN',
            purpose: 'transaction_processing',
            maskedIdentifier: '4242',
        );

        self::assertSame('pci_dss', $event->regulation());
        self::assertSame('card_data_accessed', $event->eventType());
        self::assertSame(1, CardDataAccessed::SCHEMA_VERSION);

        $restored = CardDataAccessed::fromArray($event->toArray());
        self::assertSame('4242', $restored->maskedIdentifier);
    }

    #[Test]
    public function pciDssKeyRotatedRoundTrip(): void
    {
        $event = new KeyRotated(
            eventId: 'evt-16',
            occurredAt: new DateTimeImmutable(self::TIMESTAMP),
            correlationId: 'corr-16',
            nonce: 'nonce16',
            operatorIdentity: 'key-admin',
            keyPurpose: 'card_encryption',
            previousKeyId: 'key-v1',
            newKeyId: 'key-v2',
            rotationReason: 'scheduled_rotation',
        );

        self::assertSame('pci_dss', $event->regulation());
        self::assertSame('key_rotated', $event->eventType());

        $restored = KeyRotated::fromArray($event->toArray());
        self::assertSame('key-v2', $restored->newKeyId);
    }

    #[Test]
    public function pciDssAccessControlChangedRoundTrip(): void
    {
        $event = new PciAccessControlChanged(
            eventId: 'evt-17',
            occurredAt: new DateTimeImmutable(self::TIMESTAMP),
            correlationId: 'corr-17',
            nonce: 'nonce17',
            operatorIdentity: 'admin-1',
            targetIdentity: 'user-5',
            changeType: 'grant',
            permission: 'card_data.read',
            reason: 'new_role_assignment',
        );

        self::assertSame('pci_dss', $event->regulation());
        self::assertSame('access_control_changed', $event->eventType());

        $restored = PciAccessControlChanged::fromArray($event->toArray());
        self::assertSame('grant', $restored->changeType);
    }

    #[Test]
    public function pciDssPenetrationTestCompletedRoundTrip(): void
    {
        $event = new PenetrationTestCompleted(
            eventId: 'evt-18',
            occurredAt: new DateTimeImmutable(self::TIMESTAMP),
            correlationId: 'corr-18',
            nonce: 'nonce18',
            testerIdentity: 'pentest-firm',
            scope: 'external_network',
            findingsCount: 5,
            criticalFindings: 1,
            testDate: new DateTimeImmutable(self::TIMESTAMP),
        );

        self::assertSame('pci_dss', $event->regulation());
        self::assertSame('penetration_test_completed', $event->eventType());

        $restored = PenetrationTestCompleted::fromArray($event->toArray());
        self::assertSame(5, $restored->findingsCount);
    }

    #[Test]
    public function pciDssVulnerabilityFoundRoundTrip(): void
    {
        $event = new VulnerabilityFound(
            eventId: 'evt-19',
            occurredAt: new DateTimeImmutable(self::TIMESTAMP),
            correlationId: 'corr-19',
            nonce: 'nonce19',
            reporterIdentity: 'scanner-1',
            severity: 'critical',
            cveId: 'CVE-2025-1234',
            affectedComponent: 'payment-gateway',
            remediationDeadline: new DateTimeImmutable('2025-07-15T00:00:00.000000+00:00'),
        );

        self::assertSame('pci_dss', $event->regulation());
        self::assertSame('vulnerability_found', $event->eventType());

        $restored = VulnerabilityFound::fromArray($event->toArray());
        self::assertSame('CVE-2025-1234', $restored->cveId);
    }

    #[Test]
    public function soxFinancialDataModifiedRoundTrip(): void
    {
        $event = new FinancialDataModified(
            eventId: 'evt-20',
            occurredAt: new DateTimeImmutable(self::TIMESTAMP),
            correlationId: 'corr-20',
            nonce: 'nonce20',
            modifierIdentity: 'accountant-1',
            entityType: 'journal_entry',
            entityId: 'je-1001',
            fieldSnapshots: ['amount' => ['before' => 100, 'after' => 200]],
            reason: 'correction',
        );

        self::assertSame('sox', $event->regulation());
        self::assertSame('financial_data_modified', $event->eventType());
        self::assertSame(1, FinancialDataModified::SCHEMA_VERSION);

        $restored = FinancialDataModified::fromArray($event->toArray());
        self::assertSame('je-1001', $restored->entityId);
        self::assertSame(['amount' => ['before' => 100, 'after' => 200]], $restored->fieldSnapshots);
    }

    #[Test]
    public function soxAccessControlChangedRoundTrip(): void
    {
        $event = new SoxAccessControlChanged(
            eventId: 'evt-21',
            occurredAt: new DateTimeImmutable(self::TIMESTAMP),
            correlationId: 'corr-21',
            nonce: 'nonce21',
            operatorIdentity: 'admin-2',
            targetIdentity: 'user-10',
            changeType: 'revoke',
            permission: 'financial.modify',
            justification: 'role_change',
        );

        self::assertSame('sox', $event->regulation());
        self::assertSame('access_control_changed', $event->eventType());

        $restored = SoxAccessControlChanged::fromArray($event->toArray());
        self::assertSame('role_change', $restored->justification);
    }

    #[Test]
    public function soxAuditTrailVerifiedRoundTrip(): void
    {
        $event = new AuditTrailVerified(
            eventId: 'evt-22',
            occurredAt: new DateTimeImmutable(self::TIMESTAMP),
            correlationId: 'corr-22',
            nonce: 'nonce22',
            verifierIdentity: 'auditor-2',
            verificationPeriod: '2025-Q2',
            chainIntegrity: true,
            entriesVerified: 50000,
        );

        self::assertSame('sox', $event->regulation());
        self::assertSame('audit_trail_verified', $event->eventType());

        $restored = AuditTrailVerified::fromArray($event->toArray());
        self::assertTrue($restored->chainIntegrity);
        self::assertSame(50000, $restored->entriesVerified);
    }

    #[Test]
    public function soxControlTestCompletedRoundTrip(): void
    {
        $event = new ControlTestCompleted(
            eventId: 'evt-23',
            occurredAt: new DateTimeImmutable(self::TIMESTAMP),
            correlationId: 'corr-23',
            nonce: 'nonce23',
            testerIdentity: 'auditor-3',
            controlId: 'CTRL-001',
            controlDescription: 'Segregation of duties in AP',
            testResult: 'pass',
            findings: 'No exceptions noted',
        );

        self::assertSame('sox', $event->regulation());
        self::assertSame('control_test_completed', $event->eventType());

        $restored = ControlTestCompleted::fromArray($event->toArray());
        self::assertSame('pass', $restored->testResult);
    }

    #[Test]
    public function doraIctIncidentDetectedRoundTrip(): void
    {
        $event = new IctIncidentDetected(
            eventId: 'evt-24',
            occurredAt: new DateTimeImmutable(self::TIMESTAMP),
            correlationId: 'corr-24',
            nonce: 'nonce24',
            reporterIdentity: 'monitoring-system',
            incidentType: 'service_outage',
            severity: 'major',
            affectedSystems: ['payment-api', 'trading-engine'],
            description: 'Core trading platform unresponsive',
        );

        self::assertSame('dora', $event->regulation());
        self::assertSame('ict_incident_detected', $event->eventType());
        self::assertSame(1, IctIncidentDetected::SCHEMA_VERSION);

        $restored = IctIncidentDetected::fromArray($event->toArray());
        self::assertSame(['payment-api', 'trading-engine'], $restored->affectedSystems);
    }

    #[Test]
    public function doraResilienceTestCompletedRoundTrip(): void
    {
        $event = new ResilienceTestCompleted(
            eventId: 'evt-25',
            occurredAt: new DateTimeImmutable(self::TIMESTAMP),
            correlationId: 'corr-25',
            nonce: 'nonce25',
            testerIdentity: 'chaos-team',
            testType: 'failover',
            targetSystem: 'database-cluster',
            result: 'pass',
            recoveryTimeActual: 'PT45S',
        );

        self::assertSame('dora', $event->regulation());
        self::assertSame('resilience_test_completed', $event->eventType());

        $restored = ResilienceTestCompleted::fromArray($event->toArray());
        self::assertSame('PT45S', $restored->recoveryTimeActual);
    }

    #[Test]
    public function doraThirdPartyRiskAssessedRoundTrip(): void
    {
        $event = new ThirdPartyRiskAssessed(
            eventId: 'evt-26',
            occurredAt: new DateTimeImmutable(self::TIMESTAMP),
            correlationId: 'corr-26',
            nonce: 'nonce26',
            assessorIdentity: 'risk-officer',
            providerName: 'CloudProvider Inc',
            riskLevel: 'low',
            findings: ['soc2_certified', 'iso27001'],
            nextReviewDate: new DateTimeImmutable('2026-06-15T00:00:00.000000+00:00'),
        );

        self::assertSame('dora', $event->regulation());
        self::assertSame('third_party_risk_assessed', $event->eventType());

        $restored = ThirdPartyRiskAssessed::fromArray($event->toArray());
        self::assertSame(['soc2_certified', 'iso27001'], $restored->findings);
    }

    #[Test]
    public function doraRecoveryInitiatedRoundTrip(): void
    {
        $event = new RecoveryInitiated(
            eventId: 'evt-27',
            occurredAt: new DateTimeImmutable(self::TIMESTAMP),
            correlationId: 'corr-27',
            nonce: 'nonce27',
            operatorIdentity: 'ops-lead',
            incidentId: 'INC-2025-001',
            recoveryPlan: 'failover_to_dr_site',
            estimatedRecoveryTime: 'PT2H',
        );

        self::assertSame('dora', $event->regulation());
        self::assertSame('recovery_initiated', $event->eventType());

        $restored = RecoveryInitiated::fromArray($event->toArray());
        self::assertSame('INC-2025-001', $restored->incidentId);
    }

    #[Test]
    public function amlCustomerVerifiedRoundTrip(): void
    {
        $event = new CustomerVerified(
            eventId: 'evt-28',
            occurredAt: new DateTimeImmutable(self::TIMESTAMP),
            correlationId: 'corr-28',
            nonce: 'nonce28',
            verifierIdentity: 'compliance-agent',
            customerPseudonym: 'pseudo-c1',
            verificationType: 'enhanced_due_diligence',
            verificationLevel: 'level_3',
            documentTypes: ['passport', 'utility_bill'],
        );

        self::assertSame('aml', $event->regulation());
        self::assertSame('customer_verified', $event->eventType());
        self::assertSame(1, CustomerVerified::SCHEMA_VERSION);

        $restored = CustomerVerified::fromArray($event->toArray());
        self::assertSame(['passport', 'utility_bill'], $restored->documentTypes);
    }

    #[Test]
    public function amlSuspiciousActivityDetectedRoundTrip(): void
    {
        $event = new SuspiciousActivityDetected(
            eventId: 'evt-29',
            occurredAt: new DateTimeImmutable(self::TIMESTAMP),
            correlationId: 'corr-29',
            nonce: 'nonce29',
            detectorIdentity: 'ml-model-v3',
            customerPseudonym: 'pseudo-c2',
            activityType: 'structured_deposits',
            riskScore: 0.92,
            description: 'Multiple deposits just below reporting threshold',
        );

        self::assertSame('aml', $event->regulation());
        self::assertSame('suspicious_activity_detected', $event->eventType());

        $restored = SuspiciousActivityDetected::fromArray($event->toArray());
        self::assertSame(0.92, $restored->riskScore);
    }

    #[Test]
    public function amlTransactionScreenedRoundTrip(): void
    {
        $event = new TransactionScreened(
            eventId: 'evt-30',
            occurredAt: new DateTimeImmutable(self::TIMESTAMP),
            correlationId: 'corr-30',
            nonce: 'nonce30',
            screenerIdentity: 'screening-engine',
            transactionId: 'txn-12345',
            customerPseudonym: 'pseudo-c3',
            screeningResult: 'clear',
            matchedRules: ['rule-10k-threshold'],
        );

        self::assertSame('aml', $event->regulation());
        self::assertSame('transaction_screened', $event->eventType());

        $restored = TransactionScreened::fromArray($event->toArray());
        self::assertSame(['rule-10k-threshold'], $restored->matchedRules);
    }

    #[Test]
    public function amlSanctionsCheckedRoundTrip(): void
    {
        $event = new SanctionsChecked(
            eventId: 'evt-31',
            occurredAt: new DateTimeImmutable(self::TIMESTAMP),
            correlationId: 'corr-31',
            nonce: 'nonce31',
            checkerIdentity: 'sanctions-service',
            customerPseudonym: 'pseudo-c4',
            sanctionsListVersion: '2025-06-01',
            result: 'no_match',
            matchDetails: '',
        );

        self::assertSame('aml', $event->regulation());
        self::assertSame('sanctions_checked', $event->eventType());

        $restored = SanctionsChecked::fromArray($event->toArray());
        self::assertSame('2025-06-01', $restored->sanctionsListVersion);
    }

    #[Test]
    public function allComplianceEventsCarryNonceAndCorrelationId(): void
    {
        $events = [
            new ConsentGranted('e1', new DateTimeImmutable(), 'corr', 'nonce', 's', 'p', 'l', 'c', null),
            new ConsentRevoked('e2', new DateTimeImmutable(), 'corr', 'nonce', 's', 'p', 'r'),
            new PhiAccessed('e3', new DateTimeImmutable(), 'corr', 'nonce', 'a', 'p', [], 'pur', 'am'),
            new CardDataAccessed('e4', new DateTimeImmutable(), 'corr', 'nonce', 'a', 'PAN', 'p', '4242'),
            new FinancialDataModified('e5', new DateTimeImmutable(), 'corr', 'nonce', 'm', 'e', 'i', [], 'r'),
            new IctIncidentDetected('e6', new DateTimeImmutable(), 'corr', 'nonce', 'r', 't', 's', [], 'd'),
            new CustomerVerified('e7', new DateTimeImmutable(), 'corr', 'nonce', 'v', 'c', 'vt', 'vl', []),
        ];

        foreach ($events as $event) {
            self::assertSame('corr', $event->correlationId);
            self::assertSame('nonce', $event->nonce);
        }
    }
}
