<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Compliance\Evidence;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Compliance\ComplianceFramework;
use Pulsar\Compliance\Control\ControlEvidence;
use Pulsar\Compliance\Control\ControlOutcome;
use Pulsar\Compliance\Control\Observation;
use Pulsar\Compliance\Control\ObservationGrade;
use Pulsar\Compliance\Control\ObservationId;
use Pulsar\Compliance\Control\PlatformCapability;
use Pulsar\Compliance\Evidence\ControlEvidenceGatherer;
use Pulsar\Compliance\Report\ReportVocabulary;
use Pulsar\Tests\Unit\Compliance\Support\DeploymentUnderAssessment;

use function sprintf;

/**
 * Each runtime check is graded by what it EXERCISES, and this is where that is
 * asserted against the real gatherer rather than against a fixture.
 *
 * The defect these assertions exist for was one method.
 * {@see ControlEvidenceGatherer} had a single `fromRuntimeCheck()` that wrapped
 * every result from {@see \Pulsar\Compliance\Verification\RuntimeVerifier} in
 * {@see Observation::measured()}, on the reasoning that a check which executes is
 * a measurement. Three checks went through it and they are three different kinds
 * of fact:
 *
 *  - `runtime.sodium_extension` is `extension_loaded('sodium')`. It answers out
 *    of the PHP build, identically on a deployment that encrypts every field and
 *    on one that encrypts nothing — and at grade Measured it was the sole
 *    admissible proof under nine controls across seven frameworks.
 *  - `runtime.fips_mode` reads which cipher suite this deployment BOUND and
 *    judges it against an approved list. That is a resolution.
 *  - `runtime.master_key_derived` takes the key in service and runs the KDF
 *    against it, checking length, reproducibility and domain separation. That is
 *    a measurement, and the reason the repair had to be a per-call-site split
 *    rather than a blanket regrade: a blanket one would have thrown this away.
 *
 * A fourth fact moved with them from the other direction: `master_key_material`
 * came from the security-posture preflight, which reads PULSAR_MASTER_KEY out of
 * the environment. It was Measured because the value is not in a config file.
 * Where a configured value is read from does not change what reading it
 * establishes.
 *
 * THE ASSERTIONS ARE ON THE GRADE AND ON WHAT THE GRADE DECIDES, never on a
 * table of expected grades kept beside the implementation: a hand-maintained
 * grade table is the same instrument the mapping literals were, and it would
 * agree with whatever the gatherer happens to do.
 */
#[CoversClass(ControlEvidenceGatherer::class)]
#[CoversClass(ObservationGrade::class)]
#[CoversClass(PlatformCapability::class)]
#[CoversClass(ReportVocabulary::class)]
final class RuntimeFactGradeTest extends TestCase
{
    private static ?DeploymentUnderAssessment $deployment = null;

    /**
     * Gathering opens a database session, derives a key and executes health
     * checks, so the deployment is built and observed once for the whole class.
     */
    private static function deployment(): DeploymentUnderAssessment
    {
        return self::$deployment ??= DeploymentUnderAssessment::fullyEquipped(ComplianceFramework::cases());
    }

    private static function equipped(): ControlEvidence
    {
        return self::deployment()->evidence();
    }

    // --- The four facts that moved -------------------------------------------

    /**
     * The one that carried nine controls. The extension is loaded on this
     * runtime — the suite could not run without it — so this is the reading that
     * used to be a green Satisfied, taken at the grade it deserves.
     */
    #[Test]
    public function aLoadedExtensionIsAvailableAndNotAMeasurement(): void
    {
        $observation = self::equipped()->observation(ObservationId::CryptographicCapability);

        self::assertSame(ObservationGrade::Available, $observation->grade);
        self::assertTrue($observation->present, 'ext-sodium is required to run this suite at all.');
        self::assertFalse(
            $observation->isAdmissibleAsProof(),
            'A capability the PHP build ships with answers the same on a deployment that '
                . 'encrypts everything and on one that encrypts nothing, so it cannot decide '
                . 'a control about either.',
        );
    }

    /**
     * FIPS is graded Resolved rather than Available, and the difference is not
     * cosmetic: what decides it is which cipher suite this deployment bound.
     *
     * The brief that ordered the regrade said Available; review said a module
     * build is not an exercisable behaviour and resolution of the bound suite is
     * the honest ceiling. Review won, on the argument that justifies the Available
     * case existing at all — `(available)` would tell an assessor FIPS was on
     * offer and unused, when a present reading here means it is in use.
     */
    #[Test]
    public function fipsValidationIsResolvedBecauseWhatDecidesItIsTheBoundSuite(): void
    {
        $observation = self::equipped()->observation(ObservationId::FipsValidatedCryptography);

        self::assertSame(ObservationGrade::Resolved, $observation->grade);
        self::assertFalse($observation->isAdmissibleAsProof());
    }

    /**
     * And the one that keeps Measured, because it is the only one of the three
     * that could not answer without this deployment's own key.
     */
    #[Test]
    public function runningTheKeyDerivationIsStillAMeasurement(): void
    {
        $observation = self::equipped()->observation(ObservationId::KeyDerivationVerified);

        self::assertSame(ObservationGrade::Measured, $observation->grade);
        self::assertTrue($observation->present);
        self::assertTrue(
            $observation->isAdmissibleAsProof(),
            'The KDF ran against the key in service and three properties of the result were '
                . 'checked. Demoting this along with the other two would have removed the one '
                . 'legitimate measurement in the group.',
        );
    }

    /**
     * The posture item, which reads an environment variable and two booleans.
     */
    #[Test]
    public function readingTheMasterKeyOutOfTheEnvironmentIsADeclaration(): void
    {
        $observation = self::equipped()->observation(ObservationId::MasterKeyMaterial);

        self::assertSame(ObservationGrade::Declared, $observation->grade);
        self::assertFalse($observation->isAdmissibleAsProof());
    }

    // --- What the regrade decides --------------------------------------------

    /**
     * The property the four assertions above are instances of, held over the
     * whole gathered set: only a measurement is proof.
     *
     * Stated over every fact rather than over the four that moved, so a fifth
     * fact regraded tomorrow cannot quietly become admissible.
     */
    #[Test]
    public function nothingButAMeasurementIsAdmissibleAnywhereInTheGatheredSet(): void
    {
        foreach (self::equipped()->all() as $observation) {
            if (!$observation->isAdmissibleAsProof()) {
                continue;
            }

            self::assertSame(
                ObservationGrade::Measured,
                $observation->grade,
                sprintf('%s is admissible as proof at grade %s.', $observation->id->value, $observation->grade->value),
            );
        }
    }

    /**
     * A5's companion, and the reason the regrade was worth its cost: nine controls
     * across seven frameworks reached Satisfied on a loaded extension, in the
     * shipped default.
     *
     * The four left below read as claimed and not observed, and each finding names
     * the fact and the grade it was obtained at, so an assessor can see which half
     * of the report decided nothing. GDPR Art 5(1)(f) was the example this docblock
     * used to give and is no longer here: ADR-0066 measured the estate it names, so
     * it is asserted in {@see aSatisfiedControlNamesTheMeasurementUnderIt()}
     * instead — which is the outcome this regrade was meant to force and not a
     * softening of it.
     */
    #[Test]
    #[DataProvider('everyControlThatRestedOnALoadedExtension')]
    public function aControlThatRestedOnALoadedExtensionNowSaysSo(
        ComplianceFramework $framework,
        string $control,
    ): void {
        $finding = self::deployment()->finding($framework, $control);

        self::assertNotSame(
            ControlOutcome::Satisfied,
            $finding->outcome,
            sprintf(
                '%s/%s is Satisfied on a deployment whose only exercised cryptographic fact is '
                    . 'that ext-sodium is installed.',
                $framework->value,
                $control,
            ),
        );
        self::assertStringContainsString('Claimed and not observed', $finding->summary);
        self::assertStringContainsString(
            ObservationId::CryptographicCapability->value . ' — ',
            $finding->summary,
            'The finding must name the fact that decided nothing, not merely omit it.',
        );
        self::assertStringContainsString('(available)', $finding->summary);
    }

    /**
     * The controls of the nine that are STILL carried by nothing that can reach
     * Measured, each verified from the mapping rather than from a list of ids.
     *
     * The list shrank by three when ADR-0066 measured the personal-data estate,
     * and what is left is exactly the controls whose estates nothing in this tree
     * has been through: `health_data`, `confidential_information` and
     * `cryptographic_platform`. That is the shape to check when this list changes
     * again — a control leaves it because an observer was written for the estate
     * it names, never because a fact was widened to cover it.
     *
     * ISO 27001 A.8.24 and PCI DSS Req 3.4 are deliberately NOT here. Both were in
     * the nine, and neither ever lost its Satisfied — A.8.24 through the
     * key-derivation measurement and Req 3.4 through the token vault actually
     * rendering a value unreadable — which is the evidence that the regrade
     * demoted the fact rather than the controls.
     *
     * @return iterable<string, array{ComplianceFramework, string}>
     */
    public static function everyControlThatRestedOnALoadedExtension(): iterable
    {
        yield 'hipaa/164.312(a)(2)(iv)' => [ComplianceFramework::Hipaa, '164.312(a)(2)(iv)'];
        yield 'hipaa/164.312(a)(2)(iv)-2026' => [ComplianceFramework::Hipaa, '164.312(a)(2)(iv)-2026'];
        yield 'nis2/NIS2-Art21(h)' => [ComplianceFramework::Nis2, 'NIS2-Art21(h)'];
        yield 'nist_csf/NIST-PR.DS' => [ComplianceFramework::NistCsf, 'NIST-PR.DS'];
    }

    /**
     * The controls of the nine that Satisfied on a real measurement, and the fact
     * under each named.
     *
     * Asserted so that the movement in the recorded figures is a statement about
     * which controls have proof rather than a number nobody can decompose. Two of
     * these never lost their Satisfied; three left the provider above, and each
     * left it because {@see \Pulsar\Compliance\Evidence\PersonalDataSealObserver}
     * was written for the estate they name — not because the loaded extension was
     * re-admitted, which the assertion beside them keeps true.
     *
     * @return iterable<string, array{ComplianceFramework, string, ObservationId}>
     */
    public static function everyControlCarriedByAMeasurement(): iterable
    {
        yield 'iso27001/A.8.24' => [
            ComplianceFramework::Iso27001,
            'A.8.24',
            ObservationId::KeyDerivationVerified,
        ];
        yield 'pci_dss/Req3.4' => [
            ComplianceFramework::PciDss,
            'Req3.4',
            ObservationId::TokenVaultRendersUnreadable,
        ];
        yield 'ccpa/CCPA-1798.150' => [
            ComplianceFramework::Ccpa,
            'CCPA-1798.150',
            ObservationId::PersonalDataFieldSealed,
        ];
        yield 'gdpr/Art5(1)(f)' => [
            ComplianceFramework::Gdpr,
            'Art5(1)(f)',
            ObservationId::PersonalDataFieldSealed,
        ];
        yield 'gdpr/Art32' => [
            ComplianceFramework::Gdpr,
            'Art32',
            ObservationId::PersonalDataFieldSealed,
        ];
    }

    #[Test]
    #[DataProvider('everyControlCarriedByAMeasurement')]
    public function aSatisfiedControlNamesTheMeasurementUnderIt(
        ComplianceFramework $framework,
        string $control,
        ObservationId $fact,
    ): void {
        $finding = self::deployment()->finding($framework, $control);

        self::assertSame(
            ControlOutcome::Satisfied,
            $finding->outcome,
            sprintf('%s/%s is not satisfied on the most equipped deployment.', $framework->value, $control),
        );
        self::assertStringContainsString($fact->value, $finding->summary);
        self::assertSame(
            ObservationGrade::Measured,
            self::equipped()->observation($fact)->grade,
            sprintf('%s carries a control at a grade that proves nothing.', $fact->value),
        );
    }

    // --- And the reader gets the word ----------------------------------------

    /**
     * The whole case for a fifth grade rests on the report printing it, so the
     * report has to print it — for every grade, not for the ones a test remembered.
     *
     * A grade with no word would render as an empty evidence column beside a
     * compliance finding, which is the one column a reader has to consult to tell
     * evidence from context.
     */
    #[Test]
    public function everyGradeIsSpelledForTheReport(): void
    {
        foreach (ObservationGrade::cases() as $grade) {
            self::assertSame(
                $grade->value,
                ReportVocabulary::grade($grade),
                sprintf('Grade %s has no word the report can print.', $grade->name),
            );
        }
    }

    /**
     * And the two context grades are spelled differently from each other.
     *
     * `available` and `resolved` decide the same thing — nothing — so no code
     * distinguishes them. The distinction is entirely for the assessor reading the
     * page: one says the platform ships a primitive, the other says which class is
     * bound, and a report that printed one word for both would be back to the
     * ambiguity the grades exist to remove.
     */
    #[Test]
    public function availableAndResolvedAreNotTheSameWordOnThePage(): void
    {
        self::assertNotSame(
            ReportVocabulary::grade(ObservationGrade::Available),
            ReportVocabulary::grade(ObservationGrade::Resolved),
        );
        self::assertFalse(ObservationGrade::Available->provesBehaviour());
        self::assertFalse(ObservationGrade::Resolved->provesBehaviour());
        self::assertTrue(ObservationGrade::Measured->provesBehaviour());
    }
}
