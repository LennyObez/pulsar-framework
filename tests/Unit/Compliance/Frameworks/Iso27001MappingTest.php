<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Compliance\Frameworks;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Compliance\ComplianceFramework;
use Pulsar\Compliance\Control\ControlOutcome;
use Pulsar\Compliance\Frameworks\Iso27001Mapping;
use Pulsar\Compliance\Probe\DataLeakagePreventionProbe;
use Pulsar\Compliance\Probe\KeyManagementProbe;
use Pulsar\Compliance\Probe\TamperEvidentAuditProbe;
use Pulsar\Security\Audit\AuditFileSink;
use Pulsar\Security\Compliance\Pseudonymization\PseudonymizationService;
use Pulsar\Security\Crypto\MasterKey;
use Pulsar\Security\Session\SessionEncryption;
use Pulsar\Tests\Unit\Compliance\Support\DeploymentUnderAssessment;

/**
 * ISO/IEC 27001:2022 Annex A, assessed against deployments that do and do not
 * have the thing.
 *
 * Nine of these ten used to read Implemented. Four of the nine were claims no
 * software can make — the policy set, the secure development life cycle, the
 * protection of user endpoint devices, and the approval of application security
 * requirements — and they now name the artefact instead of carrying an outcome
 * at all. A fifth, A.8.24, kept its probe and had it replaced: "including
 * cryptographic key management" was being answered by a MasterKey object
 * existing, which proves a constructor ran.
 */
#[CoversClass(Iso27001Mapping::class)]
#[CoversClass(KeyManagementProbe::class)]
#[CoversClass(TamperEvidentAuditProbe::class)]
#[CoversClass(DataLeakagePreventionProbe::class)]
final class Iso27001MappingTest extends TestCase
{
    private const string SESSION_ENCRYPTION = 'Pulsar\Security\Session\SessionEncryption';
    private const string MASTER_KEY = 'Pulsar\Security\Crypto\MasterKey';
    private const string AUDIT_SINK = 'Pulsar\Security\Audit\AuditSinkInterface';
    private const string PSEUDONYMIZATION = 'Pulsar\Security\Compliance\Pseudonymization\PseudonymizationServiceInterface';

    #[Test]
    public function declaresTenControls(): void
    {
        self::assertCount(10, Iso27001Mapping::declarations());
    }

    // --- A.8.1 user endpoint devices ----------------------------------------

    /**
     * A.8.1 was decided by the session cipher and the cookie flags, and reached
     * Satisfied when a `SessionEncryption` resolved. The control is about laptops
     * and phones: what may be stored on them, how they are enrolled, whether they
     * can be wiped. A hardened cookie is a real fact about a different subject,
     * and Pulsar has never seen an endpoint device.
     */
    #[Test]
    public function endpointDevicesIsAnOperatorResponsibility(): void
    {
        $finding = self::bare()
            ->resolving(self::SESSION_ENCRYPTION, SessionEncryption::class)
            ->finding(ComplianceFramework::Iso27001, 'A.8.1');

        self::assertSame(ControlOutcome::OperatorResponsibility, $finding->outcome);
        self::assertNull($finding->probeId);
        self::assertStringContainsString('enrolled devices', $finding->declaration->operatorArtefact);
        self::assertFalse($finding->outcome->countsTowardCoverage());
    }

    // --- A.8.15 logging ------------------------------------------------------

    #[Test]
    public function loggingIsAGapWithNoAuditSink(): void
    {
        $finding = self::bare()->finding(ComplianceFramework::Iso27001, 'A.8.15');

        self::assertSame(ControlOutcome::Unsatisfied, $finding->outcome);
    }

    /**
     * A.8.15 is about LOGGING — the record of activity the application writes —
     * and a deployment with a resolved sink and a verified evidence chain does not
     * satisfy it.
     *
     * This test asserted Satisfied until {@see \Pulsar\Compliance\Control\ControlSubject}
     * arrived, and the assertion was wrong in a way no grade rule could see. The
     * chain verification is a genuine cryptographic measurement, at the strongest
     * grade in the vocabulary — of the COMPLIANCE EVIDENCE REGISTER, the framework's
     * signed log of its own verification runs. Nothing here reads the audit trail
     * A.8.15 regulates, so the finding now says so and names the estate each fact
     * interrogated.
     */
    #[Test]
    public function loggingIsNotSatisfiedByAVerifiedComplianceRegister(): void
    {
        $finding = self::bare()
            ->resolving(self::AUDIT_SINK, AuditFileSink::class)
            ->withVerifiedEvidenceChain()
            ->finding(ComplianceFramework::Iso27001, 'A.8.15');

        self::assertSame(ControlOutcome::Unsatisfied, $finding->outcome);
        self::assertStringContainsString('compliance_evidence_register', $finding->summary);
        self::assertStringContainsString('audit_trail', $finding->summary);
    }

    /**
     * A.8.15, PCI Req 10.2 and NIST DE.AE are three standards asking one question.
     * They cite the same probe, so they cannot disagree about the same deployment —
     * which the three separate prose descriptions they used to carry could.
     */
    #[Test]
    public function loggingCitesTheSameProbeAsTheOtherAuditControls(): void
    {
        $deployment = self::bare()->resolving(self::AUDIT_SINK, AuditFileSink::class);

        self::assertSame(
            'probe.tamper_evident_audit',
            $deployment->finding(ComplianceFramework::Iso27001, 'A.8.15')->probeId,
        );
    }

    // --- A.8.24 cryptography and key management ------------------------------

    #[Test]
    public function keyManagementIsNotSatisfiedWithoutAMasterKey(): void
    {
        $finding = self::bare()->finding(ComplianceFramework::Iso27001, 'A.8.24');

        self::assertNotSame(ControlOutcome::Satisfied, $finding->outcome);
        self::assertSame('probe.key_management', $finding->probeId);
        self::assertNotSame([], $finding->remediations);
    }

    /**
     * The control says "including cryptographic key management", and it used to be
     * satisfied by a `MasterKey` object existing in the container -- which proves a
     * constructor ran and nothing else. A key that cannot derive is not key
     * management, and this deployment has one: the object is bound, and the KDF has
     * no material to work from.
     */
    #[Test]
    public function aBoundMasterKeyThatCannotDeriveDoesNotSatisfyKeyManagement(): void
    {
        $finding = self::bare()
            ->resolving(self::MASTER_KEY, MasterKey::class)
            ->finding(ComplianceFramework::Iso27001, 'A.8.24');

        self::assertNotSame(ControlOutcome::Satisfied, $finding->outcome);
    }

    #[Test]
    public function keyManagementIsSatisfiedWhenTheKeyHierarchyActuallyDerives(): void
    {
        $finding = self::bare()
            ->withWorkingTokenVault()
            ->finding(ComplianceFramework::Iso27001, 'A.8.24');

        self::assertSame(ControlOutcome::Satisfied, $finding->outcome);
        self::assertStringContainsString('domain', self::evidenceText($finding->evidence));
    }

    /**
     * A.8.24 asks for rules to be defined AND implemented. Only one of those is a
     * question about software, and the report says which one it answered.
     */
    #[Test]
    public function theNarrowedScopeOfKeyManagementIsStatedWhereAReaderWillSeeIt(): void
    {
        $finding = self::bare()->finding(ComplianceFramework::Iso27001, 'A.8.24');

        self::assertStringContainsString('ASSESSED NARROWLY', $finding->declaration->requirement);
        self::assertStringContainsString('are documents', $finding->declaration->requirement);
    }

    // --- A.8.26 application security requirements ----------------------------

    /**
     * "Identified, specified and approved" is an act people perform before an
     * application is built or bought. It was decided by whether any security
     * feature had been left bound-but-inert: a useful observation about the wiring
     * of this release, and not about any requirements process.
     */
    #[Test]
    public function applicationSecurityRequirementsIsAnOperatorResponsibility(): void
    {
        $finding = self::bare()->finding(ComplianceFramework::Iso27001, 'A.8.26');

        self::assertSame(ControlOutcome::OperatorResponsibility, $finding->outcome);
        self::assertNull($finding->probeId);
        self::assertStringContainsString('who approved them', $finding->declaration->operatorArtefact);
    }

    // --- A.8.12 data leakage prevention --------------------------------------

    #[Test]
    public function dataLeakagePreventionIsAGapWithNoPseudonymizationService(): void
    {
        $finding = self::bare()->finding(ComplianceFramework::Iso27001, 'A.8.12');

        self::assertSame(ControlOutcome::Unsatisfied, $finding->outcome);
    }

    /**
     * A resolved pseudonymization service is REPORTED and does not satisfy A.8.12.
     *
     * This test asserted Satisfied until `ObservationGrade::provesBehaviour()` was
     * narrowed to Measured. What the deployment shows here is that
     * `PseudonymizationService` is the class bound to the contract — which is "the
     * class is bound", the claim ADR-0041 proved worthless — and nothing has put a
     * value through it. Pulsar has no measurement for this control, so the honest
     * report is a gap that names the class it found and says what is missing.
     * Inventing a measurement to keep the control green would be the defect this
     * whole subsystem exists to prevent.
     */
    #[Test]
    public function dataLeakagePreventionIsClaimedAndNotObservedWhenPseudonymizationOnlyResolves(): void
    {
        $finding = self::bare()
            ->resolving(self::PSEUDONYMIZATION, PseudonymizationService::class)
            ->finding(ComplianceFramework::Iso27001, 'A.8.12');

        self::assertSame(ControlOutcome::Unsatisfied, $finding->outcome);
        self::assertStringContainsString('Claimed and not observed', $finding->summary);
        self::assertStringContainsString('(resolved)', $finding->summary);
        self::assertNotSame([], $finding->remediations);
    }

    // --- The two that were never software at all ------------------------------

    /**
     * A.5.1 used to read Partial, graded from the existence of CSP, CORS and
     * rate-limit settings. Those are not an approved, published, acknowledged and
     * reviewed policy set, and no configuration ever will be.
     */
    #[Test]
    public function securityPolicyIsAnOperatorResponsibilityNamingItsArtefact(): void
    {
        $finding = self::bare()->finding(ComplianceFramework::Iso27001, 'A.5.1');

        self::assertSame(ControlOutcome::OperatorResponsibility, $finding->outcome);
        self::assertNull($finding->probeId);
        self::assertStringContainsString('policy set', $finding->declaration->operatorArtefact);
        self::assertFalse($finding->outcome->countsTowardCoverage());
    }

    /**
     * A.8.25 used to read Implemented, graded from the presence of static-analysis
     * configuration in the repository. Whether those tools passed for the commit in
     * service is a fact about a CI run the deployment cannot be asked about.
     */
    #[Test]
    public function secureDevelopmentLifeCycleIsAnOperatorResponsibility(): void
    {
        $finding = self::bare()->finding(ComplianceFramework::Iso27001, 'A.8.25');

        self::assertSame(ControlOutcome::OperatorResponsibility, $finding->outcome);
        self::assertStringContainsString('quality gate', $finding->declaration->operatorArtefact);
    }

    /**
     * No amount of equipping the deployment can make an operator-responsibility
     * control green: it has no probe, so nothing observed can reach it.
     */
    #[Test]
    public function noObservationCanTurnAnOperatorResponsibilityIntoCoverage(): void
    {
        $finding = self::bare()
            ->resolving(self::SESSION_ENCRYPTION, SessionEncryption::class)
            ->resolving(self::MASTER_KEY, MasterKey::class)
            ->resolving(self::AUDIT_SINK, AuditFileSink::class)
            ->withVerifiedEvidenceChain()
            ->finding(ComplianceFramework::Iso27001, 'A.5.1');

        self::assertSame(ControlOutcome::OperatorResponsibility, $finding->outcome);
        self::assertSame([], $finding->evidence);
    }

    private static function bare(): DeploymentUnderAssessment
    {
        return DeploymentUnderAssessment::withNothing([ComplianceFramework::Iso27001]);
    }

    /**
     * @param list<object> $evidence
     */
    private static function evidenceText(array $evidence): string
    {
        $text = '';

        foreach ($evidence as $observation) {
            /** @var object{detail: string} $observation */
            $text .= $observation->detail . PHP_EOL;
        }

        return $text;
    }
}
