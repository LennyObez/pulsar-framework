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
use Pulsar\Compliance\Evidence\AiMonitoringObserver;
use Pulsar\Compliance\Frameworks\Iso42001Mapping;
use Pulsar\Compliance\Probe\AiMonitoringProbe;
use Pulsar\Extension\AiGovernance\Internal\Compliance\AiGovernanceDrill;
use Pulsar\Extension\AiGovernance\Internal\Monitoring\GovernanceConformityHook;
use Pulsar\Tests\Unit\Compliance\Support\AiGovernanceDeployment;
use Pulsar\Tests\Unit\Compliance\Support\DeploymentUnderAssessment;

/**
 * Clause 9.1 is EXERCISED, and the control the audit named can reach both
 * outcomes for the first time.
 *
 * WHAT THIS FILE IS FOR. ISO 42001 Clause 9.1 was registered as Implemented on
 * the strength of `MonitoringHookInterface`, which had ZERO implementations
 * anywhere in this repository. The probe was then rewritten to ask for a RESOLVED
 * hook, which was honest and inert: no deployment could bind one, so the clause
 * was stuck Unsatisfied for a reason that was a gap in the framework rather than
 * a property of any deployment. ADR-0062's own residue calls that the same class
 * of broken instrument as a control that can never be Unsatisfied.
 *
 * BOTH HALVES OF THE CLAUSE ARE ASSERTED SEPARATELY, because they fail
 * separately and a deployment can have either without the other:
 *
 *  - {@see aDeploymentWithNoHookIsObservedMonitoringNothing()} — the tree's own
 *    position until rc.12.
 *  - {@see monitoringThatIsNotRetainedDoesNotSatisfyTheClause()} — the half that
 *    running a hook does not reach. The clause closes by requiring documented
 *    information to be retained as evidence of the results, and a deployment that
 *    monitors continuously and drops every result presents an auditor with
 *    exactly what a deployment that never monitored presents.
 */
#[CoversClass(AiMonitoringObserver::class)]
#[CoversClass(AiMonitoringProbe::class)]
#[CoversClass(AiGovernanceDrill::class)]
#[CoversClass(Iso42001Mapping::class)]
final class AiMonitoringObserverTest extends TestCase
{
    private const string MONITORING = 'ISO42001-9.1';

    private const string OPERATION_MONITORING = 'ISO42001-A.7';

    private const string CONTINUAL_IMPROVEMENT = 'ISO42001-10.1';

    // --- The fact reaches present ---------------------------------------------

    #[Test]
    public function aDeploymentThatMonitorsAndRetainsIsObservedDoingBoth(): void
    {
        $observation = new AiMonitoringObserver()->observe(AiGovernanceDeployment::durable());

        self::assertSame(ObservationGrade::Measured, $observation->grade);
        self::assertTrue($observation->present);
        self::assertStringContainsString('Monitoring runs and its results are kept', $observation->detail);
    }

    /**
     * The hook that answered is the one the extension registers, and the record
     * it produced names it. A registration that satisfied a gate and then
     * measured nothing would be the substitution this whole subsystem exists to
     * refuse.
     */
    #[Test]
    public function theShippedHookIsWhatAnswersAndWhatIsRetained(): void
    {
        $drill = AiGovernanceDeployment::durable();

        (void) new AiMonitoringObserver()->observe($drill);

        $results = $drill->runMonitoring(AiGovernanceRecordObserver::PROBE_MODEL);

        self::assertNotSame([], $results);
        self::assertSame(GovernanceConformityHook::NAME, $results[0]['hook']);

        $retained = $drill->readRetainedMonitoringBack(AiGovernanceRecordObserver::PROBE_MODEL);

        self::assertSame(1, $retained['retained']);
        self::assertSame(GovernanceConformityHook::NAME, $retained['records'][0]['hook']);
    }

    /**
     * A monitoring record is an event, so it cannot be bounded by a key the way a
     * declaration or an inventory entry is. This check takes its own write back
     * instead, and the disposal is asserted rather than described.
     */
    #[Test]
    public function theCheckDisposesOfTheRecordsItWrote(): void
    {
        $drill = AiGovernanceDeployment::durable();
        $observer = new AiMonitoringObserver();

        (void) $observer->observe($drill);
        $second = $observer->observe($drill);

        self::assertTrue($second->present);
        self::assertStringContainsString('disposed of again', $second->detail);
        self::assertSame(
            0,
            $drill->readRetainedMonitoringBack(AiGovernanceRecordObserver::PROBE_MODEL)['retained'],
            'a compliance report must not grow the monitoring history of a model that serves nothing',
        );
    }

    /**
     * The probe model's own health is reported and does not decide the fact.
     * Grading Clause 9.1 on whether one synthetic record passes its checks would
     * let a deployment satisfy the clause by having nothing wrong with a model
     * nobody uses.
     */
    #[Test]
    public function theHealthOfTheProbeModelIsReportedAndDoesNotDecideTheFact(): void
    {
        $observation = new AiMonitoringObserver()->observe(AiGovernanceDeployment::durable());

        self::assertTrue($observation->present);
        self::assertStringContainsString('does not decide this fact', $observation->detail);
    }

    // --- The fact reaches absent ----------------------------------------------

    /**
     * The tree's position until rc.12, for every deployment in the world:
     * `MonitoringHookInterface` had no implementation, so monitoring a registered
     * model ran nothing at all.
     */
    #[Test]
    public function aDeploymentWithNoHookIsObservedMonitoringNothing(): void
    {
        $observation = new AiMonitoringObserver()
            ->observe(AiGovernanceDeployment::durableWithNoMonitoringHook());

        self::assertSame(ObservationGrade::Measured, $observation->grade);
        self::assertFalse($observation->present);
        self::assertStringContainsString('No monitoring hook is registered', $observation->detail);
    }

    /**
     * The other half. The hooks run, and the results go nowhere — which is
     * Clause 9.1's closing sentence unmet, and it is invisible to anything that
     * only asks whether a hook ran.
     */
    #[Test]
    public function monitoringThatIsNotRetainedDoesNotSatisfyTheClause(): void
    {
        $observation = new AiMonitoringObserver()
            ->observe(AiGovernanceDeployment::monitoringWithoutRetention());

        self::assertFalse($observation->present);
        self::assertStringContainsString('the results are retained as documented information', $observation->detail);
    }

    #[Test]
    public function aDeploymentWithoutTheExtensionReportsThatNothingRan(): void
    {
        $observation = new AiMonitoringObserver()->observe(null);

        self::assertSame(ObservationGrade::Measured, $observation->grade);
        self::assertFalse($observation->present);
        self::assertStringContainsString('no monitoring hook ran', $observation->detail);
    }

    // --- What the controls do with it -----------------------------------------

    /**
     * Clause 9.1, Annex A.7 and Clause 10.1 all cited the missing interface, and
     * all three are satisfiable now. This is the first release in which any
     * deployment of this framework can reach them.
     */
    #[Test]
    public function theThreeMonitoringControlsAreSatisfiedByADeploymentThatMonitorsAndRetains(): void
    {
        $deployment = self::deployment(AiGovernanceDeployment::durable());

        foreach ([self::MONITORING, self::OPERATION_MONITORING, self::CONTINUAL_IMPROVEMENT] as $id) {
            $finding = $deployment->finding(ComplianceFramework::Iso42001, $id);

            self::assertSame(ControlOutcome::Satisfied, $finding->outcome, $id);
            self::assertSame('probe.ai_monitoring', $finding->probeId, $id);
        }
    }

    /**
     * And what carries them is the exercised fact rather than a resolved
     * contract. Asserting the outcome alone would pass if a resolved identity had
     * quietly become admissible again.
     */
    #[Test]
    public function whatCarriesClause91IsTheExercisedFactAndNotTheResolvedHook(): void
    {
        $finding = self::deployment(AiGovernanceDeployment::durable())
            ->finding(ComplianceFramework::Iso42001, self::MONITORING);

        $exercised = null;
        $resolved = null;

        foreach ($finding->evidence as $observation) {
            if ($observation->id === ObservationId::AiMonitoringExercised) {
                $exercised = $observation;
            }

            if ($observation->id === ObservationId::AiMonitoringHookResolved) {
                $resolved = $observation;
            }
        }

        self::assertNotNull($exercised);
        self::assertSame(ObservationGrade::Measured, $exercised->grade);
        self::assertTrue($exercised->present);

        // The resolved hook is still printed beside it as corroboration, and it is
        // absent here because this fixture binds the drill rather than the
        // contract — which is exactly why it may not be what decides the control.
        self::assertNotNull($resolved);
        self::assertFalse($resolved->present);
        self::assertSame(ControlOutcome::Satisfied, $finding->outcome);
    }

    #[Test]
    public function theMonitoringControlsAreGapsWhenNoHookIsRegistered(): void
    {
        $deployment = self::deployment(AiGovernanceDeployment::durableWithNoMonitoringHook());

        foreach ([self::MONITORING, self::OPERATION_MONITORING, self::CONTINUAL_IMPROVEMENT] as $id) {
            $finding = $deployment->finding(ComplianceFramework::Iso42001, $id);

            self::assertSame(ControlOutcome::Unsatisfied, $finding->outcome, $id);
            self::assertNotSame([], $finding->remediations, $id);
        }
    }

    /**
     * A deployment with no health check registered no longer fails the AI
     * monitoring clause. Health checks cover the serving path's liveness, which
     * is a different estate from an AI system's behaviour; requiring them meant
     * Clause 9.1 could fail for a reason with nothing to do with AI.
     */
    #[Test]
    public function healthChecksAreCorroborationAndNoLongerARequirement(): void
    {
        $finding = self::deployment(AiGovernanceDeployment::durable())
            ->finding(ComplianceFramework::Iso42001, self::MONITORING);

        self::assertSame(ControlOutcome::Satisfied, $finding->outcome);
    }

    private static function deployment(AiGovernanceDrillInterface $drill): DeploymentUnderAssessment
    {
        return DeploymentUnderAssessment::withNothing([ComplianceFramework::Iso42001])
            ->withExtension('pulsar/ai-governance')
            ->resolvingInstance(AiGovernanceDrillInterface::class, $drill);
    }
}
