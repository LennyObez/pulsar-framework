<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Compliance\Control;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Compliance\ComplianceFramework;
use Pulsar\Compliance\Control\ControlAssessment;
use Pulsar\Compliance\Control\ControlDeclaration;
use Pulsar\Compliance\Control\ControlEvidence;
use Pulsar\Compliance\Control\ControlFinding;
use Pulsar\Compliance\Control\ControlOutcome;
use Pulsar\Compliance\Control\CoverageSummary;
use Pulsar\Compliance\Control\ObservationGrade;
use Pulsar\Compliance\Control\ObservationId;
use Pulsar\Compliance\ControlCatalog;
use Pulsar\Compliance\Probe\CryptographicControlProbe;
use Pulsar\Compliance\Probe\PanAtRestProbe;
use Pulsar\Compliance\Probe\RecoveryCapabilityProbe;
use Pulsar\Tests\Support\Compliance\ReflectedVocabulary;
use Pulsar\Tests\Support\Compliance\SyntheticObservation;

use function array_map;
use function in_array;

/**
 * Running the catalog against one gathered fact set.
 *
 * The arithmetic is the part worth guarding. A coverage percentage that counts
 * controls the framework never assessed is inflated without a single false claim
 * being made, which is why operator-responsibility and not-applicable controls
 * leave the denominator and are reported as their own figures.
 */
#[CoversClass(ControlAssessment::class)]
#[CoversClass(CoverageSummary::class)]
#[CoversClass(ControlFinding::class)]
#[CoversClass(ControlCatalog::class)]
final class ControlAssessmentTest extends TestCase
{
    #[Test]
    public function everyDeclarationYieldsExactlyOneFinding(): void
    {
        $findings = self::assessment()->assessAll(self::evidence(present: true));

        self::assertCount(4, $findings);
    }

    #[Test]
    public function aFindingCarriesTheProbeThatConcludedIt(): void
    {
        $findings = self::assessment()->assessAll(self::evidence(present: true));
        $ids = [];

        foreach ($findings as $finding) {
            $ids[$finding->declaration->id] = $finding->probeId;
        }

        self::assertSame('probe.pan_at_rest', $ids['probed-satisfied']);
        self::assertNull($ids['operator'], 'A control with no probe must name no probe.');
    }

    /**
     * Gaps first, then partials, then everything that passed. An operator reading
     * the report must not have to scroll past the good news to find the work.
     */
    #[Test]
    public function findingsAreOrderedWorstFirst(): void
    {
        $outcomes = array_map(
            static fn(ControlFinding $finding): ControlOutcome => $finding->outcome,
            self::assessment()->assessAll(self::evidence(present: true)),
        );

        $ranks = array_map(
            static fn(ControlOutcome $outcome): int => $outcome->severityRank(),
            $outcomes,
        );

        $sorted = $ranks;
        sort($sorted);

        self::assertSame($sorted, $ranks);
    }

    #[Test]
    public function assessingOneFrameworkLeavesTheOthersAlone(): void
    {
        $findings = self::assessment()->assessFrameworks(
            [ComplianceFramework::NistCsf],
            self::evidence(present: true),
        );

        self::assertCount(1, $findings);
        self::assertSame(ComplianceFramework::NistCsf, $findings[0]->declaration->framework);
    }

    // --- The arithmetic --------------------------------------------------------

    #[Test]
    public function operatorResponsibilityLeavesTheCoverageDenominator(): void
    {
        $summary = ControlAssessment::summarize(
            self::assessment()->assessAll(self::evidence(present: true)),
        );

        self::assertSame(1, $summary->operatorChecklist);
        self::assertSame(3, $summary->assessed, 'Only the probed controls are assessed.');
    }

    #[Test]
    public function notApplicableLeavesTheDenominatorToo(): void
    {
        $summary = ControlAssessment::summarize(
            self::assessment()->assessAll(self::evidenceWithCardholderDataOutOfScope()),
        );

        self::assertSame(1, $summary->notApplicable);
        self::assertSame(2, $summary->assessed);
    }

    /**
     * Partial contributes nothing to the percentage. Half-crediting it turns "we
     * observed part of this" into a number that reads like progress, and the
     * residual gap is already named in the finding for anyone who wants it.
     *
     * Asserted over real findings rather than over a hand-made summary, because
     * there is no longer any such thing as a hand-made summary: the constructor is
     * private and {@see CoverageSummary::over()} counts findings, so the one number
     * an operator reads first can no longer be written down by whoever is reporting
     * it.
     */
    #[Test]
    public function partialIsNotHalfCreditedInTheCoveragePercentage(): void
    {
        $findings = self::assessment()->assessAll(self::evidenceWithOnePartialControl());
        $summary = ControlAssessment::summarize($findings);

        self::assertSame(3, $summary->assessed);
        self::assertSame(1, $summary->satisfied);
        self::assertSame(1, $summary->partial);
        self::assertSame(1, $summary->gaps);
        self::assertSame(
            33.3,
            $summary->probedCoveragePercent(),
            'One satisfied of three assessed. Half-crediting the partial would read 50.',
        );
    }

    #[Test]
    public function aCatalogWithNothingToAssessReportsZeroRatherThanFullCoverage(): void
    {
        $summary = ControlAssessment::summarize([]);

        self::assertSame(0.0, $summary->probedCoveragePercent());
        self::assertFalse($summary->hasGaps());
    }

    /**
     * The second rule, at catalogue scale: a deployment whose every fact resolves
     * and whose nothing runs satisfies no control at all.
     */
    #[Test]
    public function noControlIsSatisfiedByResolvedIdentityAlone(): void
    {
        $findings = self::assessment()->assessAll(self::everythingResolved());
        $summary = ControlAssessment::summarize($findings);

        self::assertSame(0, $summary->satisfied);
        self::assertSame(3, $summary->gaps);
    }

    private static function everythingResolved(): ControlEvidence
    {
        $observations = [];

        foreach (ObservationId::cases() as $id) {
            $isScope = str_starts_with($id->value, 'scope_');

            $observations[] = SyntheticObservation::of(
                $id,
                $isScope ? ObservationGrade::Asserted : ObservationGrade::Resolved,
                true,
                'a class is wired for this fact and nothing ran',
            );
        }

        return ReflectedVocabulary::evidence(...$observations);
    }

    private static function assessment(): ControlAssessment
    {
        $catalog = new ControlCatalog();
        $catalog->register(
            ControlDeclaration::probed(
                id: 'probed-satisfied',
                framework: ComplianceFramework::PciDss,
                title: 'PAN at rest',
                requirement: 'Render PAN unreadable anywhere it is stored.',
                probe: new PanAtRestProbe(),
            ),
            ControlDeclaration::probed(
                id: 'probed-gap',
                framework: ComplianceFramework::NistCsf,
                title: 'Recovery',
                requirement: 'Restoration activities are performed.',
                probe: new RecoveryCapabilityProbe(),
            ),
            ControlDeclaration::probed(
                id: 'probed-crypto',
                framework: ComplianceFramework::PciDss,
                title: 'Cryptography',
                requirement: 'Rules for the effective use of cryptography shall be implemented.',
                probe: new CryptographicControlProbe(),
            ),
            ControlDeclaration::operatorResponsibility(
                id: 'operator',
                framework: ComplianceFramework::PciDss,
                title: 'Coding vulnerabilities',
                requirement: 'Address common coding vulnerabilities.',
                artefact: 'The CI run for the deployed commit.',
            ),
        );

        return new ControlAssessment($catalog);
    }

    private static function evidence(bool $present): ControlEvidence
    {
        return self::build($present, cardholderDataInScope: true);
    }

    private static function evidenceWithCardholderDataOutOfScope(): ControlEvidence
    {
        return self::build(present: true, cardholderDataInScope: false);
    }

    /**
     * Everything observed working except the master key provider, which leaves
     * {@see CryptographicControlProbe} with one required fact observed and one
     * absent — the shape a Partial verdict is reached from.
     */
    private static function evidenceWithOnePartialControl(): ControlEvidence
    {
        return self::build(
            present: true,
            cardholderDataInScope: true,
            absent: [ObservationId::MasterKeyResolved],
        );
    }

    /**
     * @param list<ObservationId> $absent
     */
    private static function build(bool $present, bool $cardholderDataInScope, array $absent = []): ControlEvidence
    {
        $observations = [];

        foreach (ObservationId::cases() as $id) {
            $isScope = str_starts_with($id->value, 'scope_');
            $inScope = $id === ObservationId::ScopeStoresCardholderData
                ? $cardholderDataInScope
                : true;

            $observations[] = SyntheticObservation::of(
                $id,
                // Measured, not Resolved. Resolved stopped proving behaviour, so a
                // fixture grading everything Resolved would describe a deployment in
                // which no control can be satisfied — which is a real case, asserted
                // in noControlIsSatisfiedByResolvedIdentityAlone() below, and useless
                // as the baseline for the arithmetic.
                $isScope ? ObservationGrade::Asserted : ObservationGrade::Measured,
                // The backup contract is the one fact nothing can supply, so it
                // stays absent and gives the fixture a genuine gap to count.
                $id === ObservationId::BackupPrimitiveResolved || in_array($id, $absent, true)
                    ? false
                    : ($isScope ? $inScope : $present),
                'a fact under test',
            );
        }

        return ReflectedVocabulary::evidence(...$observations);
    }
}
