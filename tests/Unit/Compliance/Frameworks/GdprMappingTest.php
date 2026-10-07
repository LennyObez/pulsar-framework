<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Compliance\Frameworks;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Compliance\ComplianceFramework;
use Pulsar\Compliance\Control\ControlOutcome;
use Pulsar\Compliance\Control\ObservationId;
use Pulsar\Compliance\Evidence\IncidentRegisterObserver;
use Pulsar\Compliance\Evidence\PseudonymizationObserver;
use Pulsar\Compliance\Frameworks\GdprMapping;
use Pulsar\Compliance\Frameworks\Nis2Mapping;
use Pulsar\Compliance\Probe\BreachNotificationProbe;
use Pulsar\Compliance\Probe\PseudonymizationProbe;
use Pulsar\Tests\Unit\Compliance\Support\DeploymentUnderAssessment;

use function array_filter;
use function count;
use function implode;

/**
 * The two controls `composer compliance:check` used to fail on, assessed against
 * deployments that do and do not have the thing.
 *
 * ADR-0046 made enabling a framework a claim the build holds you to, and GDPR is
 * enabled in `config/compliance.php`. Art 25 and Art 33 were CLAIMED AND NOT
 * OBSERVED on every default installation, deliberately, and the file said in so
 * many words what would close them and what would not: an observer that puts a
 * value through the subsystem, never something that "exercises" it by
 * constructing it.
 *
 * So the assertions here are in both directions for both controls, because a
 * control that can only reach one outcome is a broken instrument whichever
 * direction it is stuck in — and the failing direction is asserted from THREE
 * deployment shapes, not one, since the ways these subsystems fail are not
 * interchangeable: absent, present and unusable, and present and not durable.
 */
#[CoversClass(GdprMapping::class)]
#[CoversClass(Nis2Mapping::class)]
#[CoversClass(PseudonymizationProbe::class)]
#[CoversClass(BreachNotificationProbe::class)]
#[CoversClass(PseudonymizationObserver::class)]
#[CoversClass(IncidentRegisterObserver::class)]
final class GdprMappingTest extends TestCase
{
    #[Test]
    public function declaresFiveControls(): void
    {
        self::assertCount(5, GdprMapping::declarations());
    }

    // --- Art 25: pseudonymisation -------------------------------------------

    #[Test]
    public function dataProtectionByDesignIsAGapWhenNothingPseudonymises(): void
    {
        $finding = self::bare()->finding(ComplianceFramework::Gdpr, 'Art25');

        self::assertSame(ControlOutcome::Unsatisfied, $finding->outcome);
        self::assertSame('probe.pseudonymization', $finding->probeId);
        self::assertNotSame([], $finding->remediations);
        self::assertStringContainsString(
            'Neither a pseudonymisation service nor an erasure service is in service',
            self::evidenceText($finding->evidence),
        );
    }

    /**
     * The shape the control used to pass on, and the reason it could not be
     * trusted: the service is bound, resolves to the class this release accepts,
     * and cannot replace an identifier.
     *
     * `FilePseudonymLookup` refuses to read a corrupt table as an empty one — a
     * deliberate choice in that class, because answering "no mapping" from an
     * unreadable document would report an erasure as already done. So the first
     * `pseudonymize()` throws while every resolution reads clean.
     */
    #[Test]
    public function aBoundButUnusableSubsystemDoesNotSatisfyDataProtectionByDesign(): void
    {
        $finding = self::equipped()
            ->withCorruptPseudonymTable()
            ->finding(ComplianceFramework::Gdpr, 'Art25');

        self::assertSame(ControlOutcome::Unsatisfied, $finding->outcome);

        $evidence = self::evidenceText($finding->evidence);

        // The identity fact still reads clean; the difference is entirely in what
        // happened when the service was used.
        self::assertStringContainsString('FilePseudonymLookup', $evidence);
        self::assertStringContainsString('bound but not usable', $evidence);
    }

    /**
     * And the shape an in-process measurement alone would have certified: every
     * subject passes and the mappings are gone at the end of the request.
     *
     * This is ADR-0041's defect in the pseudonymisation subsystem, and it is what
     * the second essential fact exists to catch.
     */
    #[Test]
    public function aPseudonymTableThatDoesNotSurviveARestartDoesNotSatisfyIt(): void
    {
        $finding = self::equipped()
            ->withNonDurablePseudonymTable()
            ->finding(ComplianceFramework::Gdpr, 'Art25');

        self::assertSame(ControlOutcome::Unsatisfied, $finding->outcome);
        self::assertStringContainsString('InMemoryPseudonymLookup', self::evidenceText($finding->evidence));
        self::assertStringContainsString(
            'not held in a table that survives a restart',
            $finding->summary,
        );
    }

    #[Test]
    public function dataProtectionByDesignIsSatisfiedOnlyWhenAnIdentifierIsActuallyReplacedAndErased(): void
    {
        $finding = self::equipped()->finding(ComplianceFramework::Gdpr, 'Art25');

        self::assertSame(ControlOutcome::Satisfied, $finding->outcome);

        $evidence = self::evidenceText($finding->evidence);

        self::assertStringContainsString('FilePseudonymLookup', $evidence);
        self::assertStringContainsString('resolved back byte for byte', $evidence);
        self::assertStringContainsString('the erasure left nothing behind', $evidence);
    }

    /**
     * The measurement erases what it created, and the erasure is the control
     * rather than tidiness borrowed from somewhere else. A report that
     * accumulated its own rows in a re-identification table would be changing the
     * thing it measures, one run at a time.
     *
     * Asserted on the sentence the REPORT prints, not on the subject detail
     * behind it: a passing measurement publishes its summary and keeps the
     * per-subject lines for the failures, so this is the line an assessor
     * actually reads. The deletion having reached the table is asserted in
     * {@see \Pulsar\Tests\Unit\Compliance\Evidence\PseudonymizationObserverTest}.
     */
    #[Test]
    public function theArticle17ErasureIsWhatRemovesWhatTheMeasurementWrote(): void
    {
        $finding = self::equipped()->finding(ComplianceFramework::Gdpr, 'Art25');

        self::assertStringContainsString(
            'the erasure left nothing behind',
            self::evidenceText($finding->evidence),
        );
    }

    #[Test]
    public function aSatisfiedArticle25RestsOnObservedBehaviour(): void
    {
        $finding = self::equipped()->finding(ComplianceFramework::Gdpr, 'Art25');

        $admissible = array_filter(
            $finding->evidence,
            static fn(object $observation): bool => $observation->isAdmissibleAsProof(),
        );

        self::assertNotSame([], $admissible);
    }

    #[Test]
    public function article25IsNotApplicableOnlyOnTheOperatorsRecordedAssertion(): void
    {
        $finding = self::equipped()
            ->assertingScope('processes_personal_data', false)
            ->finding(ComplianceFramework::Gdpr, 'Art25');

        self::assertSame(ControlOutcome::NotApplicable, $finding->outcome);
        self::assertStringContainsString(
            'scope.processes_personal_data = false',
            self::evidenceText($finding->evidence),
        );
        self::assertFalse($finding->outcome->countsTowardCoverage());
    }

    // --- Art 33: breach notification ----------------------------------------

    #[Test]
    public function breachNotificationIsAGapWhenNoRegisterExists(): void
    {
        $finding = self::bare()->finding(ComplianceFramework::Gdpr, 'Art33');

        self::assertSame(ControlOutcome::Unsatisfied, $finding->outcome);
        self::assertSame('probe.breach_notification', $finding->probeId);
        self::assertStringContainsString(
            'No incident register is in service',
            self::evidenceText($finding->evidence),
        );
    }

    /**
     * The register that empties on restart: every subject of the measurement
     * passes and the deadline it would evidence cannot outlive the process.
     */
    #[Test]
    public function aRegisterThatDoesNotSurviveARestartDoesNotSatisfyBreachNotification(): void
    {
        $finding = self::equipped()
            ->withNonDurableIncidentRegister()
            ->finding(ComplianceFramework::Gdpr, 'Art33');

        self::assertSame(ControlOutcome::Unsatisfied, $finding->outcome);
        self::assertStringContainsString('InMemoryIncidentReporter', self::evidenceText($finding->evidence));
        self::assertStringContainsString(
            'does not survive the process that wrote to it',
            $finding->summary,
        );
    }

    #[Test]
    public function breachNotificationIsSatisfiedOnlyWhenAnIncidentIsRecordedAndReadBack(): void
    {
        $finding = self::equipped()->finding(ComplianceFramework::Gdpr, 'Art33');

        self::assertSame(ControlOutcome::Satisfied, $finding->outcome);

        $evidence = self::evidenceText($finding->evidence);

        self::assertStringContainsString('FileIncidentReporter', $evidence);
        self::assertStringContainsString('read back by id unchanged', $evidence);
    }

    /**
     * The one measurement in the evidence set that cannot undo its own write says
     * so in the evidence a reader sees, rather than only in its docblock.
     */
    #[Test]
    public function theRegisterMeasurementSaysThatWhatItWroteIsRetained(): void
    {
        $finding = self::equipped()->finding(ComplianceFramework::Gdpr, 'Art33');

        self::assertStringContainsString(
            'this register has no removal, by design',
            self::evidenceText($finding->evidence),
        );
    }

    /**
     * The same probe carries NIS2 Article 23, whose deadline is 24 hours rather
     * than 72, and one measurement has to serve both or the two mappings would
     * drift into two answers to the same question.
     */
    #[Test]
    public function theSameMeasurementCarriesTheNis2ReportingObligation(): void
    {
        $finding = self::equipped([ComplianceFramework::Nis2])
            ->finding(ComplianceFramework::Nis2, 'NIS2-Art23');

        self::assertSame(ControlOutcome::Satisfied, $finding->outcome);
        self::assertSame('probe.breach_notification', $finding->probeId);
    }

    /**
     * A scope assertion about personal data does NOT retire the breach-reporting
     * obligation, and the estate is why: NIS2 Article 23 and GDPR Article 33 are
     * declared over incident response. An entity that processes no personal data
     * still has incidents to report.
     */
    #[Test]
    public function noPersonalDataAssertionDoesNotRetireBreachReporting(): void
    {
        $finding = self::equipped()
            ->assertingScope('processes_personal_data', false)
            ->finding(ComplianceFramework::Gdpr, 'Art33');

        self::assertSame(ControlOutcome::Satisfied, $finding->outcome);
    }

    // --- Art 5(1)(f) and Art 32: the personal-data estate ---------------------

    /**
     * The two controls the gate used to fail on, from a deployment that protects
     * nothing.
     *
     * They are declared over PERSONAL DATA, and until the estate was measured
     * they could reach no other outcome — which is why the three shapes below
     * exist rather than one green assertion. On a deployment with no encryptor at
     * all, the finding is a gap that names the missing fact.
     */
    #[Test]
    public function theCryptographicArticlesAreAGapWhenNothingSealsPersonalData(): void
    {
        foreach (['Art5(1)(f)', 'Art32'] as $id) {
            $finding = self::bare()->finding(ComplianceFramework::Gdpr, $id);

            self::assertSame(ControlOutcome::Unsatisfied, $finding->outcome, $id);
            self::assertStringContainsString('No encryptor is in service', $finding->summary, $id);
            self::assertNotSame([], $finding->remediations, $id);
        }
    }

    /**
     * The shape that matters most, and the one a resolved-identity check cannot
     * tell from the one below it: an encryptor IS bound, it conceals the value and
     * gives it back, and it authenticates nothing and randomises nothing.
     *
     * Every fact about which class answered reads clean on this deployment. What
     * separates it from a sound one is entirely what happened when a field
     * classified as personal data was put through it, which is the whole reason
     * {@see \Pulsar\Compliance\Evidence\PersonalDataSealObserver} exists rather
     * than another binding inspection.
     */
    #[Test]
    public function anEncryptorThatAuthenticatesNothingDoesNotSatisfyThem(): void
    {
        foreach (['Art5(1)(f)', 'Art32'] as $id) {
            $finding = self::equipped()
                ->withUnauthenticatedFieldEncryption()
                ->finding(ComplianceFramework::Gdpr, $id);

            self::assertSame(ControlOutcome::Unsatisfied, $finding->outcome, $id);

            $evidence = self::evidenceText($finding->evidence);

            self::assertStringContainsString('is not authenticated', $evidence, $id);
            self::assertStringContainsString('produced identical stored forms', $evidence, $id);
            self::assertNotSame([], $finding->remediations, $id);
        }
    }

    /**
     * And the direction that was unreachable before ADR-0066: a deployment whose
     * at-rest protection actually holds satisfies both articles, and the evidence
     * names what ran.
     */
    #[Test]
    public function theCryptographicArticlesAreSatisfiedWhenAPersonalDataFieldIsActuallySealed(): void
    {
        foreach (['Art5(1)(f)', 'Art32'] as $id) {
            $finding = self::equipped()->finding(ComplianceFramework::Gdpr, $id);

            self::assertSame(ControlOutcome::Satisfied, $finding->outcome, $id);
            self::assertStringContainsString(
                ObservationId::PersonalDataFieldSealed->value,
                $finding->summary,
                $id,
            );
            self::assertStringContainsString(
                'one modified byte was refused',
                self::evidenceText($finding->evidence),
                $id,
            );
        }
    }

    /**
     * The session seal is measured on this deployment too and still cannot carry
     * Art 5(1)(f), so the article rests on the personal-data fact and on nothing
     * wider.
     *
     * Asserted because the probe requires both facts and a reader could otherwise
     * conclude that sealing a session finally counted for something it does not:
     * the estate join in {@see \Pulsar\Compliance\Control\ProbeVerdict::reach()}
     * lets only a personal-data fact prove a personal-data control, and the
     * summary names exactly one.
     */
    #[Test]
    public function theSessionSealStillDoesNotProveTheIntegrityArticle(): void
    {
        $finding = self::equipped()->finding(ComplianceFramework::Gdpr, 'Art5(1)(f)');

        self::assertStringNotContainsString(
            ObservationId::SessionPayloadsSealed->value . ' — ',
            $finding->summary,
        );
        self::assertStringContainsString(
            'A synthetic payload was sealed by the live session cipher',
            self::evidenceText($finding->evidence),
            'The session measurement is still printed as evidence; it just does not decide.',
        );
    }

    /**
     * And Art 30 is a document, not a deployment property: the record of
     * processing activities is written by a controller and shown to an authority.
     */
    #[Test]
    public function recordsOfProcessingActivitiesIsAnOperatorResponsibility(): void
    {
        $finding = self::bare()->finding(ComplianceFramework::Gdpr, 'Art30');

        self::assertSame(ControlOutcome::OperatorResponsibility, $finding->outcome);
        self::assertNull($finding->probeId);
        self::assertFalse($finding->outcome->countsTowardCoverage());
        self::assertStringContainsString('record of processing activities', $finding->declaration->requirement);
    }

    /**
     * Every gap this mapping reports says how to close it. A reported gap that
     * does not is a complaint.
     */
    #[Test]
    public function everyGapNamesItsRemediation(): void
    {
        foreach (['Art5(1)(f)', 'Art25', 'Art32', 'Art33'] as $id) {
            $finding = self::bare()->finding(ComplianceFramework::Gdpr, $id);

            self::assertSame(ControlOutcome::Unsatisfied, $finding->outcome, $id);
            self::assertNotSame([], $finding->remediations, $id);
            self::assertNotSame('', implode(' ', $finding->remediations), $id);
        }
    }

    private static function bare(): DeploymentUnderAssessment
    {
        return DeploymentUnderAssessment::withNothing([ComplianceFramework::Gdpr]);
    }

    /**
     * @param list<ComplianceFramework>|null $frameworks
     */
    private static function equipped(?array $frameworks = null): DeploymentUnderAssessment
    {
        return DeploymentUnderAssessment::fullyEquipped($frameworks ?? [ComplianceFramework::Gdpr]);
    }

    /**
     * @param list<object> $evidence
     */
    private static function evidenceText(array $evidence): string
    {
        $text = '';

        foreach ($evidence as $observation) {
            /** @var object{detail: string} $observation */
            $text .= $observation->detail . "\n";
        }

        self::assertGreaterThan(0, count($evidence), 'A finding must carry the evidence it rests on.');

        return $text;
    }
}
