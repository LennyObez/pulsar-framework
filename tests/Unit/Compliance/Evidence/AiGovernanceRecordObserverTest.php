<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Compliance\Evidence;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Compliance\ComplianceFramework;
use Pulsar\Compliance\Control\ControlOutcome;
use Pulsar\Compliance\Control\ObservationGrade;
use Pulsar\Compliance\Control\ObservationId;
use Pulsar\Compliance\Evidence\AiGovernanceDrillInterface;
use Pulsar\Compliance\Evidence\AiGovernanceRecordObserver;
use Pulsar\Compliance\Frameworks\Iso42001Mapping;
use Pulsar\Compliance\Probe\AiGovernanceRecordProbe;
use Pulsar\Extension\AiGovernance\Internal\Compliance\AiGovernanceDrill;
use Pulsar\Tests\Unit\Compliance\Support\AiGovernanceDeployment;
use Pulsar\Tests\Unit\Compliance\Support\DeploymentUnderAssessment;

/**
 * The AI management system record is EXERCISED, and the controls that rest on it
 * can reach both outcomes.
 *
 * WHAT THIS FILE IS FOR. Thirteen ISO 42001 controls rested on facts of the form
 * "which class answered `AiModelRegistryInterface`", and the four classes the
 * extension shipped were `InMemoryModelRegistry`,
 * `InMemoryImpactAssessmentStore`, `InMemoryExplainabilityStore` and
 * `InMemoryDataGovernanceStore` — described in the framework's own documentation
 * as *usable for development, and evidence of nothing after a restart*. The
 * report knew that only from a hard-coded list of class names, which grades a
 * store by recognising it and says nothing about a store it has not been told
 * about.
 *
 * So every shape below is BUILT and assessed rather than described: real stores,
 * the real drill the extension binds, the real observer, the real probe, and the
 * assertion is on the conclusion. Nothing here is a table of expected grades.
 *
 * THE FAILING SHAPE IS THE SHIPPED DEVELOPMENT STORE, not a hand-written fake. A
 * fake that fails a check can fail it for a reason the real code could never
 * produce, and would prove only that the observer rejects fakes. The in-memory
 * stores are a deployment somebody could actually ship — until rc.12, the one
 * every deployment got by not choosing.
 */
#[CoversClass(AiGovernanceRecordObserver::class)]
#[CoversClass(AiGovernanceRecordProbe::class)]
#[CoversClass(AiGovernanceDrill::class)]
#[CoversClass(Iso42001Mapping::class)]
final class AiGovernanceRecordObserverTest extends TestCase
{
    private const string DOCUMENTED_INFORMATION = 'ISO42001-7.5';

    private const string SYSTEM_DOCUMENTATION = 'ISO42001-A.10';

    // --- The fact reaches present, on a measurement ---------------------------

    #[Test]
    public function aDurableRecordIsObservedOutlivingTheStoreThatWroteIt(): void
    {
        $observation = new AiGovernanceRecordObserver()->observe(AiGovernanceDeployment::durable());

        self::assertSame(ObservationGrade::Measured, $observation->grade);
        self::assertTrue($observation->present);
        self::assertStringContainsString('outlives the process', $observation->detail);
    }

    /**
     * The residue is stated on the passing branch too. A note that only appears
     * when something goes wrong is a note nobody reads, and this check cannot
     * withdraw what it wrote — none of the four contracts has a removal.
     */
    #[Test]
    public function thePassingDetailNamesTheRecordsTheCheckLeavesBehind(): void
    {
        $detail = new AiGovernanceRecordObserver()->observe(AiGovernanceDeployment::durable())->detail;

        self::assertStringContainsString(AiGovernanceRecordObserver::PROBE_MODEL, $detail);
        self::assertStringContainsString(AiGovernanceRecordObserver::PROBE_DATASET, $detail);
        self::assertStringContainsString(AiGovernanceRecordObserver::PROBE_DECISION, $detail);
        self::assertStringContainsString('the next run replaces', $detail);
    }

    /**
     * Running twice leaves what running once left. Established by execution
     * rather than by reasoning about keys: the second run re-registers the same
     * reserved model and the inventory must not grow.
     */
    #[Test]
    public function aSecondRunReplacesWhatTheFirstOneWrote(): void
    {
        $drill = AiGovernanceDeployment::durable();
        $observer = new AiGovernanceRecordObserver();

        (void) $observer->observe($drill);
        $second = $observer->observe($drill);

        self::assertTrue($second->present);
        self::assertSame(1, $drill->readModelBack(AiGovernanceRecordObserver::PROBE_MODEL)['inventory_size']);
    }

    // --- The fact reaches absent, on the store that used to be the default ----

    /**
     * The shipped development stores fail, and they fail on every limb.
     *
     * This is the assertion the whole change exists for. Before rc.12 this was
     * the DEFAULT wiring: a deployment that enabled the extension and configured
     * nothing kept its AI system inventory, its impact assessments, its data
     * governance record and its explanations in the memory of one worker.
     */
    #[Test]
    public function theInMemoryStoresAreObservedLosingEveryRecord(): void
    {
        $observation = new AiGovernanceRecordObserver()->observe(AiGovernanceDeployment::inMemory());

        self::assertSame(ObservationGrade::Measured, $observation->grade);
        self::assertFalse($observation->present);
        self::assertStringContainsString('is not established as surviving', $observation->detail);
        self::assertStringContainsString('the model inventory outlives', $observation->detail);
        self::assertStringContainsString('an impact assessment outlives', $observation->detail);
        self::assertStringContainsString('the data governance record outlives', $observation->detail);
        self::assertStringContainsString('a decision explanation outlives', $observation->detail);
    }

    /**
     * The failure names what the deployment configured, so a reader learns WHY
     * the record did not survive rather than only that it did not.
     */
    #[Test]
    public function theFailureNamesTheStoresInService(): void
    {
        $detail = new AiGovernanceRecordObserver()->observe(AiGovernanceDeployment::inMemory())->detail;

        self::assertStringContainsString('registry=memory', $detail);
        self::assertStringContainsString('impact assessment=memory', $detail);
        self::assertStringContainsString('data governance=memory', $detail);
        self::assertStringContainsString('explainability=memory', $detail);
    }

    /**
     * A store this framework cannot build a second copy of is reported as such,
     * and it does not pass. A read the writer's own memory can serve establishes
     * only that the writer has memory, and the vocabulary has no word for
     * "probably fine".
     */
    #[Test]
    public function aThirdPartyStoreIsReportedRatherThanAssumedDurable(): void
    {
        $observation = new AiGovernanceRecordObserver()->observe(AiGovernanceDeployment::thirdParty());

        self::assertFalse($observation->present);
        // Reported as NOT ESTABLISHED rather than as disproved. The store may well
        // be durable; nothing looked, and the summary has to say which of the two
        // it is or a reader concludes the store is broken.
        self::assertStringContainsString('not established rather than disproved', $observation->detail);
        self::assertStringContainsString('cannot construct a second instance of', $observation->detail);
    }

    /**
     * A store that accepts a write and refuses the read is a bound-but-unusable
     * deployment, and it must be reported as a subject that RAN and failed rather
     * than as a run that could not happen.
     */
    #[Test]
    public function aStoreThatRefusesTheRegistrationIsMeasuredAsARefusal(): void
    {
        $observation = new AiGovernanceRecordObserver()->observe(AiGovernanceDeployment::refusingRegistry());

        self::assertSame(ObservationGrade::Measured, $observation->grade);
        self::assertFalse($observation->present);
        self::assertStringContainsString('bound but not usable', $observation->detail);
    }

    // --- Absent is not false --------------------------------------------------

    #[Test]
    public function aDeploymentWithoutTheExtensionReportsThatNothingWasExercised(): void
    {
        $observation = new AiGovernanceRecordObserver()->observe(null);

        self::assertSame(ObservationGrade::Measured, $observation->grade);
        self::assertFalse($observation->present);
        self::assertStringContainsString('no record was written or read back', $observation->detail);
    }

    // --- What the controls do with it -----------------------------------------

    /**
     * Clause 7.5 and Annex A.10 both ask for documentation, and both are
     * satisfiable now — on a measurement, not on a resolved class name.
     */
    #[Test]
    public function theDocumentationControlsAreSatisfiedByADurableRecord(): void
    {
        $deployment = self::deployment(AiGovernanceDeployment::durable());

        foreach ([self::DOCUMENTED_INFORMATION, self::SYSTEM_DOCUMENTATION] as $id) {
            $finding = $deployment->finding(ComplianceFramework::Iso42001, $id);

            self::assertSame(ControlOutcome::Satisfied, $finding->outcome, $id);
            self::assertSame('probe.ai_governance_record', $finding->probeId, $id);
        }
    }

    /**
     * The measurement, not the resolution, is what carries them. Asserting the
     * outcome alone would pass if a resolved identity had quietly become
     * admissible again, which is the regression this subsystem keeps having.
     */
    #[Test]
    public function whatCarriesTheDocumentationControlIsTheExercisedFact(): void
    {
        $finding = self::deployment(AiGovernanceDeployment::durable())
            ->finding(ComplianceFramework::Iso42001, self::DOCUMENTED_INFORMATION);

        $carrier = null;

        foreach ($finding->evidence as $observation) {
            if ($observation->id === ObservationId::AiGovernanceRecordsDurable) {
                $carrier = $observation;
            }
        }

        self::assertNotNull($carrier);
        self::assertSame(ObservationGrade::Measured, $carrier->grade);
        self::assertTrue($carrier->present);
    }

    /**
     * And the same deployment with the development stores does not satisfy them,
     * which is the direction that used to be impossible to express: the old fact
     * read a class name off an accept list.
     */
    #[Test]
    public function theDocumentationControlsAreGapsOnTheStoresThatUsedToBeTheDefault(): void
    {
        $deployment = self::deployment(AiGovernanceDeployment::inMemory());

        foreach ([self::DOCUMENTED_INFORMATION, self::SYSTEM_DOCUMENTATION] as $id) {
            $finding = $deployment->finding(ComplianceFramework::Iso42001, $id);

            self::assertSame(ControlOutcome::Unsatisfied, $finding->outcome, $id);
            self::assertNotSame([], $finding->remediations, $id);
        }
    }

    private static function deployment(AiGovernanceDrillInterface $drill): DeploymentUnderAssessment
    {
        return DeploymentUnderAssessment::withNothing([ComplianceFramework::Iso42001])
            ->withExtension('pulsar/ai-governance')
            ->resolvingInstance(AiGovernanceDrillInterface::class, $drill);
    }
}
