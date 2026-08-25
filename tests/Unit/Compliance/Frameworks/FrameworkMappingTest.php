<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Compliance\Frameworks;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Compliance\ComplianceFramework;
use Pulsar\Compliance\Control\ControlAssessment;
use Pulsar\Compliance\Control\ControlDeclaration;
use Pulsar\Compliance\Control\ControlEvidence;
use Pulsar\Compliance\Control\ControlFinding;
use Pulsar\Compliance\Control\ControlOutcome;
use Pulsar\Compliance\Control\Observation;
use Pulsar\Compliance\ControlCatalog;
use Pulsar\Compliance\Frameworks\CcpaMapping;
use Pulsar\Compliance\Frameworks\DoraMapping;
use Pulsar\Compliance\Frameworks\EidasMapping;
use Pulsar\Compliance\Frameworks\GdprMapping;
use Pulsar\Compliance\Frameworks\HipaaMapping;
use Pulsar\Compliance\Frameworks\Hl7FhirMapping;
use Pulsar\Compliance\Frameworks\Iso13485Mapping;
use Pulsar\Compliance\Frameworks\Iso27001Mapping;
use Pulsar\Compliance\Frameworks\Iso42001Mapping;
use Pulsar\Compliance\Frameworks\MdrMapping;
use Pulsar\Compliance\Frameworks\Nis2Mapping;
use Pulsar\Compliance\Frameworks\NistCsfMapping;
use Pulsar\Compliance\Frameworks\PciDssMapping;
use Pulsar\Compliance\Frameworks\Psd2Mapping;
use Pulsar\Compliance\Frameworks\Soc2Mapping;
use Pulsar\Compliance\Frameworks\SwiftCspMapping;
use Pulsar\Tests\Unit\Compliance\Support\DeploymentUnderAssessment;

use function array_filter;
use function array_map;
use function array_sum;
use function count;
use function sprintf;

/**
 * Every framework's declarations, assessed against a deployment that has nothing
 * and one that has everything this release can observe.
 *
 * This is the test the per-framework literal tests should always have been.
 * Sixteen files used to read a hard-coded `ControlStatus` out of a mapping and
 * assert it back — 207 assertions that could only ever detect somebody editing
 * the literal they were reading, and that stayed green through the entire life of
 * the ADR-0041 defect they were nominally covering.
 */
#[CoversClass(CcpaMapping::class)]
#[CoversClass(DoraMapping::class)]
#[CoversClass(EidasMapping::class)]
#[CoversClass(GdprMapping::class)]
#[CoversClass(HipaaMapping::class)]
#[CoversClass(Hl7FhirMapping::class)]
#[CoversClass(Iso13485Mapping::class)]
#[CoversClass(Iso27001Mapping::class)]
#[CoversClass(Iso42001Mapping::class)]
#[CoversClass(MdrMapping::class)]
#[CoversClass(Nis2Mapping::class)]
#[CoversClass(NistCsfMapping::class)]
#[CoversClass(PciDssMapping::class)]
#[CoversClass(Psd2Mapping::class)]
#[CoversClass(Soc2Mapping::class)]
#[CoversClass(SwiftCspMapping::class)]
#[CoversClass(ControlCatalog::class)]
#[CoversClass(ControlAssessment::class)]
#[CoversClass(ControlDeclaration::class)]
final class FrameworkMappingTest extends TestCase
{
    /** Every control the framework declares, across all sixteen core mappings. */
    private const int TOTAL_CONTROLS = 193;

    /**
     * What a deployment carrying every implementation this release assesses can
     * actually be observed doing, per framework.
     *
     * Recorded rather than thresholded. See
     * {@see anEquippedDeploymentSatisfiesExactlyWhatItCanMeasure()} for what these
     * numbers mean and what moving one of them requires.
     *
     * @var array<string, int>
     */
    private const array SATISFIED_WHEN_EQUIPPED = [
        'ccpa' => 1,
        'dora' => 1,
        'eidas' => 0,
        'gdpr' => 2,
        'hipaa' => 4,
        'hl7_fhir' => 1,
        'iso13485' => 2,
        'iso27001' => 3,
        'iso42001' => 1,
        'mdr' => 2,
        'nis2' => 1,
        'nist_csf' => 3,
        'pci_dss' => 2,
        'psd2' => 1,
        'soc2' => 5,
        'swift_csp' => 3,
    ];

    /**
     * @return list<ControlDeclaration>
     */
    private static function declarations(): array
    {
        return [
            ...CcpaMapping::declarations(),
            ...DoraMapping::declarations(),
            ...EidasMapping::declarations(),
            ...GdprMapping::declarations(),
            ...HipaaMapping::declarations(),
            ...Hl7FhirMapping::declarations(),
            ...Iso13485Mapping::declarations(),
            ...Iso27001Mapping::declarations(),
            ...Iso42001Mapping::declarations(),
            ...MdrMapping::declarations(),
            ...Nis2Mapping::declarations(),
            ...NistCsfMapping::declarations(),
            ...PciDssMapping::declarations(),
            ...Psd2Mapping::declarations(),
            ...Soc2Mapping::declarations(),
            ...SwiftCspMapping::declarations(),
        ];
    }

    #[Test]
    public function everyMappingDeclaresItsControlsWithoutCollision(): void
    {
        $catalog = new ControlCatalog();

        // Registration refuses duplicates, so this also proves no two mappings
        // claim the same control of the same framework.
        $catalog->register(...self::declarations());

        self::assertSame(self::TOTAL_CONTROLS, $catalog->count());
    }

    /**
     * A probed control must have a probe and an operator-responsibility control
     * must name its artefact. Both are guaranteed by the type, and this asserts
     * the guarantee holds across every declaration in the tree.
     */
    #[Test]
    public function everyDeclarationIsEitherProbedOrNamesAnAssessorArtefact(): void
    {
        foreach (self::declarations() as $declaration) {
            $label = $declaration->framework->value . '/' . $declaration->id;

            if ($declaration->isProbed()) {
                self::assertNotNull($declaration->probe, $label);
                self::assertSame('', $declaration->operatorArtefact, $label);

                continue;
            }

            self::assertNull($declaration->probe, $label);
            self::assertNotSame(
                '',
                $declaration->operatorArtefact,
                sprintf(
                    '%s has no probe, so it must name the artefact an assessor should be shown; '
                        . 'otherwise it is a control quietly excluded from coverage and from view.',
                    $label,
                ),
            );
        }
    }

    /**
     * The requirement text is the standard's, not a description of Pulsar. The
     * sentence "Covered by TokenizationService … and DatabaseTokenStore" was the
     * false claim ADR-0041 found; no requirement may name a framework class again.
     */
    #[Test]
    public function noRequirementTextClaimsCoverageByNamingAFrameworkClass(): void
    {
        foreach (self::declarations() as $declaration) {
            $label = $declaration->framework->value . '/' . $declaration->id;

            foreach (['Covered by', 'Pulsar', 'Framework provides'] as $claim) {
                self::assertStringNotContainsString($claim, $declaration->requirement, $label);
            }
        }
    }

    #[Test]
    public function everyDeclarationCarriesAnIdentifierATitleAndARequirement(): void
    {
        foreach (self::declarations() as $declaration) {
            $label = $declaration->framework->value . '/' . $declaration->id;

            self::assertNotSame('', $declaration->id, $label);
            self::assertNotSame('', $declaration->title, $label);
            self::assertNotSame('', $declaration->requirement, $label);
        }
    }

    // --- The deployment-shaped assertions ------------------------------------

    /**
     * A deployment with nothing wired satisfies nothing, anywhere.
     */
    #[Test]
    #[DataProvider('everyDeclaredFramework')]
    public function nothingIsSatisfiedOnADeploymentThatHasNothing(ComplianceFramework $framework): void
    {
        $findings = self::assess($framework, self::bare());

        self::assertNotSame([], $findings, $framework->value);

        foreach ($findings as $finding) {
            self::assertNotSame(
                ControlOutcome::Satisfied,
                $finding->outcome,
                sprintf(
                    '%s/%s reported Satisfied on a deployment with no services, no profile and '
                        . 'no extensions.',
                    $framework->value,
                    $finding->declaration->id,
                ),
            );
        }
    }

    /**
     * And a deployment carrying every implementation this release assesses
     * satisfies EXACTLY these controls — written down per framework, because the
     * number is the honest output of the design and hiding it behind a threshold
     * is how a compliance subsystem drifts.
     *
     * The figures fell hard when `ObservationGrade::provesBehaviour()` was narrowed
     * to Measured. Across the sixteen mappings a fully-equipped deployment used to
     * satisfy 78 of 96 assessed controls; it now satisfies 32. The 46 that moved
     * did not get worse — nothing about the deployment changed — they were passing
     * on resolved identity, which answers "which class is bound, and is it on the
     * allow-list" and is ADR-0041's defect one lookup deeper. Their findings now
     * read "Claimed and not observed" and name the class that was found.
     *
     * Two frameworks satisfy nothing at all on an equipped deployment. eIDAS is
     * one of them, and that is the correct report: Pulsar observes no signature
     * being created or validated, so it has nothing to say about a deployment's
     * trust services beyond which classes are wired. A number that said otherwise
     * would be the thing this subsystem exists to stop.
     */
    #[Test]
    #[DataProvider('everyDeclaredFramework')]
    public function anEquippedDeploymentSatisfiesExactlyWhatItCanMeasure(ComplianceFramework $framework): void
    {
        $findings = self::assess($framework, self::equipped());

        $satisfied = count(array_filter(
            $findings,
            static fn(ControlFinding $finding): bool => $finding->outcome === ControlOutcome::Satisfied,
        ));

        $recorded = self::SATISFIED_WHEN_EQUIPPED[$framework->value] ?? null;

        self::assertNotNull(
            $recorded,
            sprintf(
                '%s declares controls and has no recorded figure. Run the assessment against an '
                    . 'equipped deployment and write down what it can observe.',
                $framework->value,
            ),
        );
        self::assertSame(
            $recorded,
            $satisfied,
            sprintf(
                '%s satisfies a different number of controls than the recorded one. If a probe '
                    . 'gained a real measurement, raise the figure; if one lost a measurement, '
                    . 'lower it and say why in the probe. Do not adjust it to make a report look '
                    . 'better.',
                $framework->value,
            ),
        );
    }

    /**
     * Every satisfied control on an equipped deployment carries a fact that was
     * EXERCISED — not a class name, not a config value, not an operator claim.
     *
     * This is the property the count above is a consequence of, and it is the one
     * that must never be relaxed. Asserted over the whole catalogue so a probe
     * cannot be satisfied by something weaker in one framework than in another.
     */
    #[Test]
    public function everySatisfiedControlAnywhereRestsOnSomethingThatRan(): void
    {
        foreach (self::everyDeclaredFramework() as [$framework]) {
            foreach (self::assess($framework, self::equipped()) as $finding) {
                if ($finding->outcome !== ControlOutcome::Satisfied) {
                    continue;
                }

                $measured = array_filter(
                    $finding->evidence,
                    static fn(Observation $observation): bool => $observation->isAdmissibleAsProof(),
                );

                self::assertNotSame(
                    [],
                    $measured,
                    sprintf(
                        '%s/%s is Satisfied without one observation that was measured.',
                        $framework->value,
                        $finding->declaration->id,
                    ),
                );
            }
        }
    }

    /**
     * And the counter-assertion that keeps the two above honest: a probe that
     * always reports a gap would pass "nothing is satisfied on a bare deployment"
     * and would pass a per-framework figure of zero. Equipping the deployment must
     * move the total.
     */
    #[Test]
    public function equippingTheDeploymentMovesTheTotal(): void
    {
        self::assertSame(0, self::satisfiedAcrossEveryFramework(self::bare()));
        self::assertSame(
            array_sum(self::SATISFIED_WHEN_EQUIPPED),
            self::satisfiedAcrossEveryFramework(self::equipped()),
        );
        self::assertGreaterThan(0, array_sum(self::SATISFIED_WHEN_EQUIPPED));
    }

    private static function satisfiedAcrossEveryFramework(ControlEvidence $evidence): int
    {
        $satisfied = 0;

        foreach (self::everyDeclaredFramework() as [$framework]) {
            $satisfied += count(array_filter(
                self::assess($framework, $evidence),
                static fn(ControlFinding $finding): bool => $finding->outcome === ControlOutcome::Satisfied,
            ));
        }

        return $satisfied;
    }

    /**
     * Operator-responsibility controls never move, in either direction. They are
     * excluded from the coverage arithmetic and can neither fail the report nor
     * pad its percentage, which is what stops SOC 2's twenty-four organizational
     * criteria from being read as coverage.
     */
    #[Test]
    public function operatorResponsibilityControlsAreExcludedFromCoverageEverywhere(): void
    {
        $findings = [];

        foreach (self::everyDeclaredFramework() as [$framework]) {
            $findings = [...$findings, ...self::assess($framework, self::equipped())];
        }

        $checklist = array_filter(
            $findings,
            static fn(ControlFinding $finding): bool => !$finding->declaration->isProbed(),
        );

        self::assertNotSame([], $checklist);

        foreach ($checklist as $finding) {
            self::assertSame(ControlOutcome::OperatorResponsibility, $finding->outcome);
            self::assertFalse($finding->outcome->countsTowardCoverage());
            self::assertFalse($finding->isFailing(strict: true));
        }
    }

    /**
     * Coverage is reported as two numbers, never one. A catalogue that is mostly
     * checklist must not read as mostly covered.
     */
    #[Test]
    public function coverageAndChecklistAreCountedSeparately(): void
    {
        $findings = self::assess(ComplianceFramework::Soc2, self::equipped());
        $summary = ControlAssessment::summarize($findings);

        self::assertGreaterThan(0, $summary->operatorChecklist);
        self::assertSame(
            count($findings) - $summary->operatorChecklist - $summary->notApplicable,
            $summary->assessed,
            'The checklist and the not-applicable controls must both leave the denominator.',
        );
        self::assertLessThanOrEqual(100.0, $summary->probedCoveragePercent());
    }

    /**
     * @return iterable<string, array{ComplianceFramework}>
     */
    public static function everyDeclaredFramework(): iterable
    {
        $catalog = new ControlCatalog();
        $catalog->register(...self::declarations());

        foreach ($catalog->frameworks() as $framework) {
            yield $framework->value => [$framework];
        }
    }

    /**
     * @return list<ControlFinding>
     */
    private static function assess(ComplianceFramework $framework, ControlEvidence $evidence): array
    {
        $catalog = new ControlCatalog();
        $catalog->register(...self::declarations());

        return new ControlAssessment($catalog)->assessFrameworks([$framework], $evidence);
    }

    private static ?ControlEvidence $bare = null;

    private static ?ControlEvidence $equipped = null;

    /**
     * Gathering opens connections and executes health checks, so both deployments
     * are observed once for the whole class rather than once per framework.
     */
    private static function bare(): ControlEvidence
    {
        return self::$bare ??= DeploymentUnderAssessment::withNothing(self::frameworkList())
            ->withoutComplianceProfile()
            ->evidence();
    }

    private static function equipped(): ControlEvidence
    {
        return self::$equipped ??= DeploymentUnderAssessment::fullyEquipped(self::frameworkList())
            ->evidence();
    }

    /**
     * @return list<ComplianceFramework>
     */
    private static function frameworkList(): array
    {
        $catalog = new ControlCatalog();
        $catalog->register(...self::declarations());

        return array_map(
            static fn(ComplianceFramework $framework): ComplianceFramework => $framework,
            $catalog->frameworks(),
        );
    }
}
