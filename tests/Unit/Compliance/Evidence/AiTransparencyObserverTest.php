<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Compliance\Evidence;

use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Compliance\ComplianceFramework;
use Pulsar\Compliance\Control\ControlOutcome;
use Pulsar\Compliance\Control\ObservationGrade;
use Pulsar\Compliance\Control\ObservationId;
use Pulsar\Compliance\Evidence\AiTransparencyDrillInterface;
use Pulsar\Compliance\Evidence\AiTransparencyObserver;
use Pulsar\Compliance\Frameworks\AiActMapping;
use Pulsar\Compliance\Probe\AiTransparencyProbe;
use Pulsar\Extension\AiGovernance\Internal\Compliance\AiTransparencyDrill;
use Pulsar\Extension\AiGovernance\Internal\Store\InMemoryAiTransparency;
use Pulsar\Extension\AiGovernance\Transparency\AiInteractionDisclosure;
use Pulsar\Extension\AiGovernance\Transparency\AiTransparencyPolicy;
use Pulsar\Extension\AiGovernance\Transparency\SyntheticContentKind;
use Pulsar\Tests\Unit\Compliance\Support\DeploymentUnderAssessment;
use Pulsar\Tests\Unit\Compliance\Support\TransparencySeamDecorator;
use RuntimeException;

use function array_map;
use function time;

/**
 * Article 50 is EXERCISED, and the control it carries can reach both outcomes.
 *
 * WHAT THIS FILE IS FOR. The EU AI Act mapping satisfied nothing on any
 * deployment, and it was honest: both of its probes read a resolved identity, and
 * resolution stopped proving behaviour when `provesBehaviour()` was narrowed to
 * Measured. A control that can never be Satisfied is the same class of broken
 * instrument as one that can never be Unsatisfied — ADR-0062's own residue says
 * so — and Article 50 is the worst place in the catalogue to leave one, because
 * it is the single AI Act duty a framework carries a real share of and it has
 * applied since 2 August 2026.
 *
 * So every shape below is built and assessed rather than described: the real
 * composition root wires it, the real gatherer observes it, the real probe
 * concludes, and the assertion is on the conclusion. Nothing here states an
 * outcome, and nothing here is a table of expected grades — the objection that
 * killed the sixteen literal mapping tests applies to a hand-maintained grade
 * table just as squarely.
 *
 * THE DEFECTIVE SUBSYSTEMS ARE DECORATORS OVER THE REAL ONE, each breaking
 * exactly one thing and passing everything else through. That is deliberate: a
 * hand-written fake that fails a check can fail it for a reason the real code
 * could never produce, and would prove only that the observer rejects fakes. A
 * decorator that forgets a declaration, or marks a surface nobody declared, or
 * stamps its own clock, is a deployment somebody could actually ship.
 */
#[CoversClass(AiTransparencyObserver::class)]
#[CoversClass(AiTransparencyDrill::class)]
#[CoversClass(AiTransparencyProbe::class)]
#[CoversClass(AiActMapping::class)]
final class AiTransparencyObserverTest extends TestCase
{
    private const string CONTROL = 'ai-act-art-50-capability';

    /** A surface an application declared for itself, before any assessment ran. */
    private const string APPLICATION_SURFACE = 'support.chat';

    // --- The control reaches Satisfied, on a measurement ----------------------

    /**
     * A deployment whose transparency subsystem works satisfies the control, and
     * what carries it is a fact that was exercised on the estate the control
     * regulates.
     *
     * Both halves are asserted. The outcome alone would pass if a resolved
     * identity had quietly become admissible again, which is exactly the
     * regression this subsystem keeps having.
     */
    #[Test]
    public function anEquippedDeploymentIsObservedDischargingArticle50(): void
    {
        $deployment = self::equipped();
        $observation = $deployment->evidence()->observation(ObservationId::AiTransparencyExercised);

        self::assertSame(ObservationGrade::Measured, $observation->grade);
        self::assertTrue($observation->present);
        self::assertTrue($observation->isAdmissibleAsProof());

        $finding = $deployment->finding(ComplianceFramework::AiAct, self::CONTROL);

        self::assertSame(ControlOutcome::Satisfied, $finding->outcome);
        self::assertStringContainsString(ObservationId::AiTransparencyExercised->value, $finding->summary);
        self::assertSame(
            $finding->declaration->assessedSubject(),
            ObservationId::AiTransparencyExercised->subject(),
            'The fact must interrogate the estate the control declares, or it cannot carry it.',
        );
    }

    /**
     * And the report says what ran, in words an assessor can check against the
     * subsystem rather than take on trust.
     */
    #[Test]
    public function theEvidenceLineNamesWhatWasExercised(): void
    {
        $detail = self::equipped()
            ->evidence()
            ->observation(ObservationId::AiTransparencyExercised)
            ->detail;

        self::assertStringContainsString('declared', $detail);
        self::assertStringContainsString('mark', $detail);
        self::assertStringContainsString('refused', $detail);
        self::assertStringContainsString(
            AiTransparencyObserver::PROBE_SURFACE,
            $detail,
            'A measurement that writes must name what it left behind, on the passing branch too.',
        );
    }

    // --- Absent is not false, and it is not NotApplicable either ---------------

    /**
     * The extension is trust tier `verified` and kind `product`: it does not load
     * unless an operator enables it. A deployment without it must report that
     * NOTHING WAS EXERCISED — a gap — and must not report a subject that does not
     * exist, which would retire the control instead of failing it.
     *
     * That evaporation is the defect ADR-0062 spent itself removing from
     * transmission security, and it would be worse here: a deployment with no way
     * to declare an Article 50 position is not out of scope for a duty that has
     * bound since 2 August 2026.
     */
    #[Test]
    public function aDeploymentWithoutTheExtensionReportsThatNothingWasExercised(): void
    {
        $bare = DeploymentUnderAssessment::withNothing([ComplianceFramework::AiAct]);
        $observation = $bare->evidence()->observation(ObservationId::AiTransparencyExercised);

        self::assertSame(ObservationGrade::Measured, $observation->grade);
        self::assertFalse($observation->present);
        self::assertTrue(
            $observation->subjectExists,
            'A missing transparency subsystem is a gap, never an absent subject.',
        );
        self::assertStringContainsString('nothing was exercised', $observation->detail);

        $finding = $bare->finding(ComplianceFramework::AiAct, self::CONTROL);

        self::assertSame(ControlOutcome::Unsatisfied, $finding->outcome);
        self::assertTrue($finding->outcome->countsTowardCoverage());
        self::assertNotSame([], $finding->remediations);
    }

    // --- The other direction: four deployments that fail it -------------------

    /**
     * A subsystem that accepts a declaration and forgets it.
     *
     * This is the failure the whole observer exists for. Every identity fact reads
     * clean on such a deployment — the contract resolves to the accepted
     * implementation — and no surface it declares survives the call that made it.
     */
    #[Test]
    public function aSubsystemThatForgetsWhatItWasToldCannotSatisfyTheControl(): void
    {
        $finding = self::withSeam(new class (self::realDrill()) extends TransparencySeamDecorator {
            /**
             * @return array{
             *     surface_id: string,
             *     owes_disclosure: bool,
             *     owes_marking: bool,
             *     notice: string|null,
             *     locale: string|null
             * }|null
             */
            #[Override]
            public function policyFor(string $surfaceId): ?array
            {
                return null;
            }

            /**
             * @return list<string>
             */
            #[Override]
            public function declaredSurfaces(): array
            {
                return [];
            }
        })->finding(ComplianceFramework::AiAct, self::CONTROL);

        self::assertSame(ControlOutcome::Unsatisfied, $finding->outcome);
        self::assertStringContainsString('did not survive the call that made it', $finding->summary);
    }

    /**
     * A subsystem that will mark anything it is handed.
     *
     * A mark that traces to no declared policy resolves to no risk classification
     * and no accountable surface, so it asserts nothing an assessor can
     * corroborate — and Article 50(2) asks for marking that is RELIABLE.
     */
    #[Test]
    public function aSubsystemThatMarksAnUndeclaredSurfaceCannotSatisfyTheControl(): void
    {
        $finding = self::withSeam(new class (self::realDrill()) extends TransparencySeamDecorator {
            /**
             * @return array{header: string, machine_readable: array<string, scalar>}
             */
            #[Override]
            public function mark(string $surfaceId, string $kind, string $modelId, int $generatedAt): array
            {
                // Declare on demand, which is what "mark anything" amounts to.
                $this->inner->declareSurface($surfaceId, 'A notice.', 'en', $kind);

                return $this->inner->mark($surfaceId, $kind, $modelId, $generatedAt);
            }
        })->finding(ComplianceFramework::AiAct, self::CONTROL);

        self::assertSame(ControlOutcome::Unsatisfied, $finding->outcome);
        self::assertStringContainsString('was never declared', $finding->summary);
    }

    /**
     * A marker that stamps its own clock.
     *
     * The transparency contract states that no generation time is taken from a
     * clock, because whatever produced an output knows when it did and a marker
     * guessing at it records the time of MARKING and calls it the time of
     * generation. Handing in an instant that is plainly not now and requiring it
     * back is how that becomes a measurement rather than a sentence in a docblock.
     */
    #[Test]
    public function aMarkerThatRecordsWhenItMarkedCannotSatisfyTheControl(): void
    {
        $finding = self::withSeam(new class (self::realDrill()) extends TransparencySeamDecorator {
            /**
             * @return array{header: string, machine_readable: array<string, scalar>}
             */
            #[Override]
            public function mark(string $surfaceId, string $kind, string $modelId, int $generatedAt): array
            {
                return $this->inner->mark($surfaceId, $kind, $modelId, time());
            }
        })->finding(ComplianceFramework::AiAct, self::CONTROL);

        self::assertSame(ControlOutcome::Unsatisfied, $finding->outcome);
        self::assertStringContainsString('when the mark was made', $finding->summary);
    }

    /**
     * A store that keeps every declaration it is given rather than keying them by
     * surface.
     *
     * That store would accumulate one entry per compliance report, for ever. The
     * observer cannot withdraw what it declares — `AiTransparencyInterface` has no
     * withdrawal — so the bound has to be established instead of assumed, and this
     * is the deployment where assuming it would be wrong.
     */
    #[Test]
    public function aStoreThatAccumulatesADeclarationPerReportCannotSatisfyTheControl(): void
    {
        $finding = self::withSeam(new class (self::realDrill()) extends TransparencySeamDecorator {
            /** @var list<string> */
            private array $log = [];

            #[Override]
            public function declareSurface(string $surfaceId, string $notice, string $locale, string $kind): void
            {
                $this->log[] = $surfaceId;
                $this->inner->declareSurface($surfaceId, $notice, $locale, $kind);
            }

            /**
             * @return list<string>
             */
            #[Override]
            public function declaredSurfaces(): array
            {
                return $this->log;
            }
        })->finding(ComplianceFramework::AiAct, self::CONTROL);

        self::assertSame(ControlOutcome::Unsatisfied, $finding->outcome);
        self::assertStringContainsString('accumulates a declaration', $finding->summary);
    }

    /**
     * And a subsystem that refuses a coherent declaration outright is a subject
     * that RAN and failed, not a check that never happened.
     */
    #[Test]
    public function aSubsystemThatRefusesToDeclareIsReportedAsHavingRefused(): void
    {
        $observation = self::withSeam(new class (self::realDrill()) extends TransparencySeamDecorator {
            #[Override]
            public function declareSurface(string $surfaceId, string $notice, string $locale, string $kind): void
            {
                throw new RuntimeException('the declaration store is read-only');
            }
        })->evidence()->observation(ObservationId::AiTransparencyExercised);

        self::assertFalse($observation->present);
        self::assertStringContainsString('bound but not usable', $observation->detail);
        self::assertStringContainsString('read-only', $observation->detail);
    }

    // --- What the measurement writes, and what it must not disturb ------------

    /**
     * THE RESIDUE, MEASURED. Three assessments against one store leave exactly one
     * declaration, under the reserved surface and no other.
     *
     * The observer is driven directly here rather than through the fixture,
     * because what is under test is what repeated runs accumulate and the fixture
     * caches its evidence set on purpose.
     */
    #[Test]
    public function repeatedAssessmentsLeaveExactlyOneDeclarationBehind(): void
    {
        $store = new InMemoryAiTransparency();
        $drill = new AiTransparencyDrill($store);
        $observer = new AiTransparencyObserver();

        for ($run = 0; $run < 3; $run++) {
            self::assertTrue($observer->observe($drill)->present);
        }

        self::assertSame(
            [AiTransparencyObserver::PROBE_SURFACE],
            array_map(
                static fn(AiTransparencyPolicy $policy): string => $policy->surfaceId,
                $store->declared(),
            ),
        );
    }

    /**
     * And it disturbs nothing the deployment had already declared.
     *
     * A compliance probe that overwrote the positions it reports on would be a
     * worse artefact than no report at all.
     */
    #[Test]
    public function anApplicationsOwnDeclarationSurvivesTheCheck(): void
    {
        $store = new InMemoryAiTransparency();
        $store->declare(new AiTransparencyPolicy(
            surfaceId: self::APPLICATION_SURFACE,
            interactsWithNaturalPersons: true,
            disclosure: new AiInteractionDisclosure('You are chatting with an AI assistant.', 'en'),
            generates: [SyntheticContentKind::Text],
        ));

        self::assertTrue(new AiTransparencyObserver()->observe(new AiTransparencyDrill($store))->present);

        $survivor = $store->policyFor(self::APPLICATION_SURFACE);

        self::assertInstanceOf(AiTransparencyPolicy::class, $survivor);
        self::assertSame('You are chatting with an AI assistant.', $survivor->disclosure?->notice);
    }

    // --- And the count does not inflate ---------------------------------------

    /**
     * The two Article 50 duties themselves stay operator artefacts on the SAME
     * deployment that satisfies the capability control.
     *
     * Whether a person saw the notice and whether real output carried the mark
     * happen where no container can look. A subsystem that CAN produce a notice
     * and a mark is not a person having read one, and grading it as though it were
     * is the inflation the whole compliance subsystem exists to prevent.
     */
    #[Test]
    public function whetherAPersonSawTheNoticeIsStillNotSomethingThisFrameworkClaims(): void
    {
        $deployment = self::equipped();

        foreach (['ai-act-art-50-1', 'ai-act-art-50-2'] as $control) {
            $finding = $deployment->finding(ComplianceFramework::AiAct, $control);

            self::assertSame(
                ControlOutcome::OperatorResponsibility,
                $finding->outcome,
                $control . ' was graded from what the framework can produce rather than from an artefact.',
            );
            self::assertFalse($finding->outcome->countsTowardCoverage());
        }
    }

    // --- Fixtures --------------------------------------------------------------

    private static function equipped(): DeploymentUnderAssessment
    {
        return DeploymentUnderAssessment::fullyEquipped([ComplianceFramework::AiAct]);
    }

    /**
     * An equipped deployment whose transparency seam is the one the test built.
     *
     * The CONTRACT stays bound to the real store, so `ai_transparency_resolved`
     * still reads clean and the only fact under test is the exercised one — which
     * is the point: every deployment below is one whose identity facts an assessor
     * would find impeccable.
     */
    private static function withSeam(AiTransparencyDrillInterface $drill): DeploymentUnderAssessment
    {
        return self::equipped()->withTransparencySeam($drill);
    }

    private static function realDrill(): AiTransparencyDrill
    {
        return new AiTransparencyDrill(new InMemoryAiTransparency());
    }
}
