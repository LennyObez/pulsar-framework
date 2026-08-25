<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Compliance\Frameworks;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Compliance\ComplianceFramework;
use Pulsar\Compliance\Control\ControlOutcome;
use Pulsar\Compliance\Evidence\ControlEvidenceGatherer;
use Pulsar\Compliance\Frameworks\NistCsfMapping;
use Pulsar\Compliance\Probe\DataProtectionAtRestProbe;
use Pulsar\Compliance\Probe\GovernanceProfileProbe;
use Pulsar\Compliance\Probe\IncidentResponseProbe;
use Pulsar\Compliance\Probe\RecoveryCapabilityProbe;
use Pulsar\Compliance\Probe\TamperEvidentAuditProbe;
use Pulsar\Security\Audit\AuditFileSink;
use Pulsar\Security\Crypto\MasterKey;
use Pulsar\Security\Incident\FileIncidentReporter;
use Pulsar\Security\Incident\InMemoryIncidentReporter;
use Pulsar\Security\Session\SessionEncryption;
use Pulsar\Tests\Unit\Compliance\Support\DeploymentUnderAssessment;

/**
 * NIST CSF 2.0, assessed against deployments that do and do not have the thing.
 *
 * Ten of the eleven outcomes used to read Implemented and RC.RP read Partial. The
 * RC.RP tests below are the ones that matter: the framework has no backup or
 * restore primitive, so recovery reports a gap on every deployment, however well
 * equipped, and no amount of adding services makes it green.
 */
#[CoversClass(NistCsfMapping::class)]
#[CoversClass(RecoveryCapabilityProbe::class)]
#[CoversClass(TamperEvidentAuditProbe::class)]
#[CoversClass(GovernanceProfileProbe::class)]
#[CoversClass(DataProtectionAtRestProbe::class)]
#[CoversClass(IncidentResponseProbe::class)]
final class NistCsfMappingTest extends TestCase
{
    private const string AUDIT_SINK = 'Pulsar\Security\Audit\AuditSinkInterface';
    private const string SESSION_ENCRYPTION = 'Pulsar\Security\Session\SessionEncryption';
    private const string MASTER_KEY = 'Pulsar\Security\Crypto\MasterKey';
    private const string INCIDENT_REPORTER = 'Pulsar\Security\Incident\IncidentReporterInterface';

    #[Test]
    public function declaresElevenOutcomes(): void
    {
        self::assertCount(11, NistCsfMapping::declarations());
    }

    // --- RC.RP: the outcome with nothing behind it ---------------------------

    #[Test]
    public function recoveryIsAGapBecauseTheFrameworkHasNoBackupPrimitive(): void
    {
        $finding = self::bare()->finding(ComplianceFramework::NistCsf, 'NIST-RC.RP');

        self::assertSame(ControlOutcome::Unsatisfied, $finding->outcome);
        self::assertSame('probe.recovery_capability', $finding->probeId);
    }

    /**
     * The evidence must name the contract nothing answered, so the report says what
     * is missing rather than only that something is.
     */
    #[Test]
    public function recoveryEvidenceNamesTheContractNothingAnswers(): void
    {
        $finding = self::bare()->finding(ComplianceFramework::NistCsf, 'NIST-RC.RP');

        $details = '';

        foreach ($finding->evidence as $observation) {
            $details .= $observation->detail . "\n";
        }

        self::assertStringContainsString(ControlEvidenceGatherer::BACKUP_SERVICE_CONTRACT, $details);
    }

    /**
     * The load-bearing test for the whole design. A deployment with everything the
     * framework can offer still fails RC.RP, because recovery is not among the
     * things the framework offers. Before this change the same deployment — and
     * every other — reported RC.RP as covered.
     */
    #[Test]
    public function recoveryStaysAGapOnAFullyEquippedDeployment(): void
    {
        $finding = self::bare()
            ->resolving(self::AUDIT_SINK, AuditFileSink::class)
            ->resolving(self::SESSION_ENCRYPTION, SessionEncryption::class)
            ->resolving(self::MASTER_KEY, MasterKey::class)
            ->resolving(self::INCIDENT_REPORTER, FileIncidentReporter::class)
            ->withVerifiedEvidenceChain()
            ->withHealthCheck(DeploymentUnderAssessment::passingHealthCheck('database'))
            ->finding(ComplianceFramework::NistCsf, 'NIST-RC.RP');

        self::assertSame(ControlOutcome::Unsatisfied, $finding->outcome);
    }

    /**
     * And it cannot be scoped away either: no deployment can assert that recovery
     * does not apply to it, so the probe reads no scope assertion at all.
     */
    #[Test]
    public function recoveryCannotBeScopedOut(): void
    {
        $finding = self::bare()
            ->assertingScope('stores_cardholder_data', false)
            ->assertingScope('processes_personal_data', false)
            ->assertingScope('processes_health_data', false)
            ->finding(ComplianceFramework::NistCsf, 'NIST-RC.RP');

        self::assertSame(ControlOutcome::Unsatisfied, $finding->outcome);
    }

    #[Test]
    public function recoveryNamesWhatWouldCloseTheGap(): void
    {
        $finding = self::bare()->finding(ComplianceFramework::NistCsf, 'NIST-RC.RP');

        self::assertNotSame([], $finding->remediations);
        self::assertStringContainsString(
            'enabled_frameworks',
            implode(' ', $finding->remediations),
            'A gap the framework cannot close must at least tell the operator that '
                . 'un-declaring the framework is the alternative.',
        );
    }

    // --- DE.AE: adverse event analysis ---------------------------------------

    #[Test]
    public function adverseEventAnalysisIsAGapWithNoAuditTrail(): void
    {
        self::assertSame(
            ControlOutcome::Unsatisfied,
            self::bare()->finding(ComplianceFramework::NistCsf, 'NIST-DE.AE')->outcome,
        );
    }

    #[Test]
    public function adverseEventAnalysisIsSatisfiedWhenTheChainVerifies(): void
    {
        $finding = self::bare()
            ->resolving(self::AUDIT_SINK, AuditFileSink::class)
            ->withVerifiedEvidenceChain()
            ->finding(ComplianceFramework::NistCsf, 'NIST-DE.AE');

        self::assertSame(ControlOutcome::Satisfied, $finding->outcome);
    }

    // --- GV.OC: organizational context ---------------------------------------

    #[Test]
    public function governanceIsAGapWhenNoComplianceProfileWasResolved(): void
    {
        $finding = self::bare()
            ->withoutComplianceProfile()
            ->finding(ComplianceFramework::NistCsf, 'NIST-GV.OC');

        self::assertSame(ControlOutcome::Unsatisfied, $finding->outcome);
    }

    /**
     * A resolved compliance profile is context, not governance.
     *
     * GV.OC was Satisfied on the profile resolving — an enumeration of what the
     * deployment has, at grade Resolved, which stopped proving behaviour. Nothing
     * in the framework exercises a governance profile, so the report now says the
     * profile was found and the control was not observed.
     */
    #[Test]
    public function governanceIsClaimedAndNotObservedWhenOnlyTheProfileResolves(): void
    {
        $finding = self::bare()->finding(ComplianceFramework::NistCsf, 'NIST-GV.OC');

        self::assertSame(ControlOutcome::Unsatisfied, $finding->outcome);
        self::assertStringContainsString('Claimed and not observed', $finding->summary);
        self::assertNotSame([], $finding->remediations);
    }

    // --- PR.DS: data security -------------------------------------------------

    #[Test]
    public function dataSecurityIsNotSatisfiedWithoutAnEncrypter(): void
    {
        $finding = self::bare()->finding(ComplianceFramework::NistCsf, 'NIST-PR.DS');

        self::assertNotSame(ControlOutcome::Satisfied, $finding->outcome);
    }

    #[Test]
    public function dataSecurityIsSatisfiedWhenTheEncrypterResolves(): void
    {
        $finding = self::bare()
            ->resolving(self::SESSION_ENCRYPTION, SessionEncryption::class)
            ->finding(ComplianceFramework::NistCsf, 'NIST-PR.DS');

        self::assertSame(ControlOutcome::Satisfied, $finding->outcome);
    }

    // --- RS.MA: incident management -------------------------------------------

    /**
     * An incident register that empties on restart cannot evidence a notification
     * deadline, so the in-memory reporter is refused by name rather than counted
     * because something was bound.
     */
    #[Test]
    public function incidentManagementRefusesTheInMemoryReporter(): void
    {
        $finding = self::bare()
            ->resolving(self::INCIDENT_REPORTER, InMemoryIncidentReporter::class)
            ->finding(ComplianceFramework::NistCsf, 'NIST-RS.MA');

        self::assertSame(ControlOutcome::Unsatisfied, $finding->outcome);
    }

    /**
     * A durable reporter is still only a resolved class name.
     *
     * The distinction the accept list draws — `FileIncidentReporter` writes
     * somewhere that survives a restart, `InMemoryIncidentReporter` does not — is
     * worth printing and is not evidence that an incident was ever reported.
     * Nothing exercises the reporter, so RS.MA reports a gap naming the class it
     * found rather than a pass resting on it.
     */
    #[Test]
    public function incidentManagementIsClaimedAndNotObservedWithADurableReporter(): void
    {
        $finding = self::bare()
            ->resolving(self::INCIDENT_REPORTER, FileIncidentReporter::class)
            ->finding(ComplianceFramework::NistCsf, 'NIST-RS.MA');

        self::assertSame(ControlOutcome::Unsatisfied, $finding->outcome);
        self::assertStringContainsString('Claimed and not observed', $finding->summary);
        self::assertStringContainsString('FileIncidentReporter', $finding->summary);
    }

    private static function bare(): DeploymentUnderAssessment
    {
        return DeploymentUnderAssessment::withNothing([ComplianceFramework::NistCsf]);
    }
}
