<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Compliance\Frameworks;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Compliance\ComplianceFramework;
use Pulsar\Compliance\Control\ControlOutcome;
use Pulsar\Compliance\Control\ObservationId;
use Pulsar\Compliance\Evidence\ControlEvidenceGatherer;
use Pulsar\Compliance\Frameworks\Iso42001Mapping;
use Pulsar\Compliance\Probe\AiAuditTrailProbe;
use Pulsar\Compliance\Probe\AiModelRegistryProbe;
use Pulsar\Compliance\Probe\AiMonitoringProbe;
use Pulsar\Security\Audit\AuditFileSink;
use Pulsar\Tests\Unit\Compliance\Support\DeploymentUnderAssessment;

use function implode;

/**
 * ISO/IEC 42001:2023, assessed against deployments that do and do not have the
 * thing.
 *
 * Thirteen of these fifteen used to read Implemented, on the strength of
 * interfaces existing inside an extension a deployment might never have enabled.
 * Clause 9.1, A.7 and 10.1 cite MonitoringHookInterface, which has zero
 * implementations anywhere in this repository — the interface, the lifecycle
 * manager that references it, and one test double are all there is. The tests
 * below fix that as a fact rather than a footnote.
 */
#[CoversClass(Iso42001Mapping::class)]
#[CoversClass(AiModelRegistryProbe::class)]
#[CoversClass(AiAuditTrailProbe::class)]
#[CoversClass(AiMonitoringProbe::class)]
final class Iso42001MappingTest extends TestCase
{
    private const string EXTENSION = ControlEvidenceGatherer::AI_GOVERNANCE_EXTENSION;
    private const string MODEL_REGISTRY = 'Pulsar\Extension\AiGovernance\Contracts\AiModelRegistryInterface';
    private const string AI_AUDIT_LOGGER = 'Pulsar\Extension\AiGovernance\Contracts\AiAuditLoggerInterface';
    private const string MONITORING_HOOK = 'Pulsar\Extension\AiGovernance\Contracts\MonitoringHookInterface';
    private const string AUDIT_SINK = 'Pulsar\Security\Audit\AuditSinkInterface';

    #[Test]
    public function declaresFifteenControls(): void
    {
        self::assertCount(15, Iso42001Mapping::declarations());
    }

    // --- Clause 7.5: documented information ----------------------------------

    #[Test]
    public function documentedInformationIsAGapWhenTheExtensionIsNotInstalled(): void
    {
        $finding = self::bare()->finding(ComplianceFramework::Iso42001, 'ISO42001-7.5');

        self::assertSame(ControlOutcome::Unsatisfied, $finding->outcome);

        // The probe changed in rc.12 and the change is the point of this
        // assertion. Clause 7.5 asks for DOCUMENTED information and was carried by
        // `probe.ai_model_registry`, which asks which class answered the registry
        // contract — a question an in-memory store answers as well as a durable
        // one. `probe.ai_governance_record` asks whether a record written through
        // the deployment's own stores is still there when a second instance looks.
        self::assertSame('probe.ai_governance_record', $finding->probeId);
    }

    /**
     * Installing the extension moves nothing.
     *
     * It used to move the control to Partial: the extension being ACTIVE was a
     * resolved fact, resolved facts proved behaviour, and half a control was
     * therefore observed on the strength of a package being present. Partial has to
     * prove the part it claims, and "the extension is installed" is not proof of
     * anything the extension does.
     */
    #[Test]
    public function documentedInformationIsStillAGapWithTheExtensionButNoRegistry(): void
    {
        $finding = self::bare()
            ->withExtension(self::EXTENSION)
            ->finding(ComplianceFramework::Iso42001, 'ISO42001-7.5');

        self::assertSame(ControlOutcome::Unsatisfied, $finding->outcome);
        self::assertNotSame([], $finding->remediations);
    }

    /**
     * The extension's own in-memory registry does NOT satisfy the control, and the
     * report says which class stood there and why.
     *
     * It used to. `InMemoryModelRegistry` was on the accept list while carrying
     * `#[Internal(reason: 'Development store; production deployments should use a
     * persistent implementation')]` — a control satisfied by a class that says in
     * its own source that it does not do the job. ISO 42001 Clause 7.5 asks for
     * documented information about the AI systems in service; a registry that
     * forgets them when the process ends documents nothing an assessor can read.
     */
    #[Test]
    public function aDevelopmentStubRegistryDoesNotSatisfyDocumentedInformation(): void
    {
        $finding = self::bare()
            ->withExtension(self::EXTENSION)
            ->resolving(self::MODEL_REGISTRY, 'Pulsar\Extension\AiGovernance\Internal\Store\InMemoryModelRegistry')
            ->finding(ComplianceFramework::Iso42001, 'ISO42001-7.5');

        self::assertNotSame(
            ControlOutcome::Satisfied,
            $finding->outcome,
            'A documented development store must never carry a control.',
        );
        self::assertNotSame([], $finding->remediations);

        $registry = null;

        foreach ($finding->evidence as $observation) {
            if ($observation->id === ObservationId::AiModelRegistryResolved) {
                $registry = $observation;
            }
        }

        self::assertNotNull($registry);
        self::assertFalse($registry->present);
        self::assertStringContainsString('InMemoryModelRegistry', $registry->detail);
        self::assertStringContainsString('development store', $registry->detail);
    }

    // --- Clause 9.2: internal audit ------------------------------------------

    #[Test]
    public function internalAuditIsAGapWithoutTheExtension(): void
    {
        self::assertSame(
            ControlOutcome::Unsatisfied,
            self::bare()->finding(ComplianceFramework::Iso42001, 'ISO42001-9.2')->outcome,
        );
    }

    /**
     * Clause 9.2 audits the AI MANAGEMENT SYSTEM, and the one measurement this
     * deployment offers interrogates the framework's own compliance evidence
     * register. Wiring an AI audit logger says which class is bound; verifying the
     * chain says the register is intact; neither is an internal audit of the AI
     * system, and the finding now names both estates instead of conflating them.
     */
    #[Test]
    public function internalAuditIsNotSatisfiedByAVerifiedComplianceRegister(): void
    {
        $finding = self::bare()
            ->withExtension(self::EXTENSION)
            ->resolving(self::AI_AUDIT_LOGGER, 'Pulsar\Extension\AiGovernance\Internal\AiAuditLogger')
            ->resolving(self::AUDIT_SINK, AuditFileSink::class)
            ->withVerifiedEvidenceChain()
            ->finding(ComplianceFramework::Iso42001, 'ISO42001-9.2');

        self::assertSame(ControlOutcome::Unsatisfied, $finding->outcome);
        self::assertStringContainsString('compliance_evidence_register', $finding->summary);
        self::assertStringContainsString('ai_system_governance', $finding->summary);
    }

    // --- Clause 9.1: the control the audit named ------------------------------

    #[Test]
    public function monitoringIsAGapWithoutTheExtension(): void
    {
        self::assertSame(
            ControlOutcome::Unsatisfied,
            self::bare()->finding(ComplianceFramework::Iso42001, 'ISO42001-9.1')->outcome,
        );
    }

    /**
     * The headline finding. Even with the extension active, an AI audit logger
     * wired, a verified evidence chain and passing health checks, Clause 9.1 is
     * not satisfied — because MonitoringHookInterface has no implementation to
     * resolve, and monitoring is what the clause is about.
     *
     * The old catalogue reported this control as Implemented on every deployment
     * in the world, including this one.
     */
    #[Test]
    public function monitoringStaysAGapBecauseNoMonitoringHookImplementationExists(): void
    {
        $finding = self::bare()
            ->withExtension(self::EXTENSION)
            ->resolving(self::AI_AUDIT_LOGGER, 'Pulsar\Extension\AiGovernance\Internal\AiAuditLogger')
            ->resolving(self::AUDIT_SINK, AuditFileSink::class)
            ->withVerifiedEvidenceChain()
            ->withHealthCheck(DeploymentUnderAssessment::passingHealthCheck('model-serving'))
            ->finding(ComplianceFramework::Iso42001, 'ISO42001-9.1');

        // It used to be Partial, and the "part observed" was the deployment's own
        // liveness checks answering — a fact about whether the process is up, not
        // about whether an AI system is monitored. With the estate join that fact
        // no longer speaks for this control, so the whole of Clause 9.1 is
        // unobserved rather than half of it.
        self::assertSame(ControlOutcome::Unsatisfied, $finding->outcome);
        self::assertStringContainsString(
            'MonitoringHookInterface',
            self::details($finding->evidence),
        );
        self::assertStringContainsString(
            'MonitoringHookInterface',
            implode(' ', $finding->remediations),
        );
    }

    /**
     * And an implementation this release has never assessed does NOT close it
     * either. The accept list refuses by default, so an unknown class is reported
     * unobserved with its name printed, rather than counted because something
     * answered — which is how a null object becomes evidence.
     */
    #[Test]
    public function anUnassessedHookImplementationIsReportedUnobserved(): void
    {
        $hook = new class {};

        $finding = self::bare()
            ->withExtension(self::EXTENSION)
            ->resolvingInstance(self::MONITORING_HOOK, $hook)
            ->withHealthCheck(DeploymentUnderAssessment::passingHealthCheck('model-serving'))
            ->finding(ComplianceFramework::Iso42001, 'ISO42001-9.1');

        // The anonymous class is not on the accept list, so the report says so
        // rather than assuming it adequate: an unassessed implementation is
        // reported unobserved, which is the conservative direction.
        self::assertNotSame(ControlOutcome::Satisfied, $finding->outcome);
        self::assertStringContainsString('not assessed', self::details($finding->evidence));
    }

    /**
     * A.7 and 10.1 cite the same missing hook, so they fail for the same reason
     * and say so with the same words.
     */
    #[Test]
    public function operationMonitoringAndContinualImprovementFailForTheSameReason(): void
    {
        $deployment = self::bare()->withExtension(self::EXTENSION);

        foreach (['ISO42001-A.7', 'ISO42001-10.1'] as $id) {
            $finding = $deployment->finding(ComplianceFramework::Iso42001, $id);

            self::assertSame('probe.ai_monitoring', $finding->probeId, $id);
            self::assertNotSame(ControlOutcome::Satisfied, $finding->outcome, $id);
        }
    }

    // --- Scoping ---------------------------------------------------------------

    /**
     * Enabling ISO 42001 in config/compliance.php IS the operator's assertion that
     * the deployment must satisfy it. There is therefore no scope key that can
     * silence its controls: the way to stop being assessed against a standard is
     * to stop declaring it, in a diff.
     */
    #[Test]
    public function noScopeAssertionCanSilenceTheStandard(): void
    {
        $finding = self::bare()
            ->assertingScope('processes_personal_data', false)
            ->assertingScope('processes_health_data', false)
            ->assertingScope('stores_cardholder_data', false)
            ->finding(ComplianceFramework::Iso42001, 'ISO42001-9.1');

        self::assertSame(ControlOutcome::Unsatisfied, $finding->outcome);
    }

    // --- A.2: an AI policy is a document ---------------------------------------

    #[Test]
    public function aiPolicyIsAnOperatorResponsibilityNamingItsArtefact(): void
    {
        $finding = self::bare()->finding(ComplianceFramework::Iso42001, 'ISO42001-A.2');

        self::assertSame(ControlOutcome::OperatorResponsibility, $finding->outcome);
        self::assertNull($finding->probeId);
        self::assertStringContainsString('AI policy', $finding->declaration->operatorArtefact);
    }

    /**
     * @param list<object> $evidence
     */
    private static function details(array $evidence): string
    {
        $text = '';

        foreach ($evidence as $observation) {
            /** @var object{detail: string} $observation */
            $text .= $observation->detail . "\n";
        }

        return $text;
    }

    private static function bare(): DeploymentUnderAssessment
    {
        return DeploymentUnderAssessment::withNothing([ComplianceFramework::Iso42001]);
    }
}
