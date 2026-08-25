<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Compliance\Control;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Compliance\Control\ControlEvidence;
use Pulsar\Compliance\Control\ControlOutcome;
use Pulsar\Compliance\Control\ControlRequirement;
use Pulsar\Compliance\Control\InadmissibleEvidenceException;
use Pulsar\Compliance\Control\ObservationGrade;
use Pulsar\Compliance\Control\ObservationId;
use Pulsar\Compliance\Control\ProbeVerdict;
use Pulsar\Compliance\Control\RequiredFact;
use Pulsar\Tests\Support\Compliance\ReflectedVocabulary;
use Pulsar\Tests\Support\Compliance\SyntheticObservation;

use function in_array;
use function sprintf;

/**
 * The decision table, exercised on the only path that can reach it.
 *
 * These properties used to be asserted against `ProbeVerdict::satisfied()` and
 * friends. Those are private now, which is a stronger statement than any of
 * these tests could make on their own — but it also means the properties have to
 * be proved where they are actually reachable: through
 * {@see ProbeVerdict::reach()}, against a gathered evidence set.
 */
#[CoversClass(ProbeVerdict::class)]
#[CoversClass(ControlRequirement::class)]
#[CoversClass(RequiredFact::class)]
#[CoversClass(InadmissibleEvidenceException::class)]
final class ProbeVerdictTest extends TestCase
{
    private const string VAULT_FIX = 'Configure a durable token store.';

    private const string CRYPTO_FIX = 'Install ext-sodium.';

    // --- Satisfied -----------------------------------------------------------

    #[Test]
    public function satisfiedNeedsAFactObservedBehavingRatherThanConfigured(): void
    {
        $verdict = ProbeVerdict::reach(self::twoFacts(), self::deployment(ObservationGrade::Measured, true));

        self::assertSame(ControlOutcome::Satisfied, $verdict->outcome);
    }

    /**
     * The general form of ADR-0041's defect: every requirement is on in the config
     * and nothing was seen doing any of it.
     */
    #[Test]
    #[DataProvider('inadmissibleGrades')]
    public function everythingClaimedAndNothingObservedIsAGap(ObservationGrade $grade): void
    {
        $verdict = ProbeVerdict::reach(self::twoFacts(), self::deployment($grade, true));

        self::assertSame(ControlOutcome::Unsatisfied, $verdict->outcome);
        self::assertStringContainsString('Claimed and not observed', $verdict->summary);
        self::assertStringContainsString('(' . $grade->value . ')', $verdict->summary);
        self::assertNotSame([], $verdict->remediations);
    }

    /**
     * Every grade that does not prove behaviour.
     *
     * Resolved joined this list when {@see ObservationGrade::provesBehaviour()} was
     * narrowed to Measured, and it is the case that matters: "which class is bound,
     * and is it on the allow-list" is ADR-0041's defect one lookup deeper, so a
     * deployment whose every requirement RESOLVES and whose nothing RUNS reaches
     * the same gap as one that only wrote it in a config file.
     *
     * @return iterable<string, array{ObservationGrade}>
     */
    public static function inadmissibleGrades(): iterable
    {
        yield 'a config read' => [ObservationGrade::Declared];
        yield 'an operator assertion' => [ObservationGrade::Asserted];
        yield 'a resolved class name' => [ObservationGrade::Resolved];
    }

    /**
     * The review finding this rule came from: admissibility was judged over
     * required-plus-supporting, so one corroborating fact that decides nothing
     * could carry a whole control.
     */
    #[Test]
    public function anAdmissibleSupportingFactCannotCarryTheVerdictAlone(): void
    {
        $requirement = ControlRequirement::of(
            required: [
                RequiredFact::contributing(ObservationId::TokenVaultPersistence, [self::VAULT_FIX]),
                RequiredFact::contributing(ObservationId::CryptographicCapability, [self::CRYPTO_FIX]),
            ],
            supporting: [ObservationId::MfaSubsystemResolved],
        );

        // Everything the control requires is configured and unobserved; the one
        // fact observed behaving is the supporting one.
        $evidence = self::deployment(ObservationGrade::Declared, true, [
            ObservationId::MfaSubsystemResolved->value => [ObservationGrade::Measured, true],
        ]);

        $verdict = ProbeVerdict::reach($requirement, $evidence);

        self::assertSame(
            ControlOutcome::Unsatisfied,
            $verdict->outcome,
            'A supporting fact corroborates a conclusion; it must never be the conclusion.',
        );
    }

    /**
     * The summary must be generated from what decided the verdict. It used to be a
     * hard-coded per-probe constant, which is the status literal restored as prose.
     */
    #[Test]
    public function theSatisfiedSummaryNamesTheEvidenceThatDecidedIt(): void
    {
        $verdict = ProbeVerdict::reach(self::twoFacts(), self::deployment(ObservationGrade::Measured, true));

        self::assertStringContainsString(ObservationId::TokenVaultPersistence->value, $verdict->summary);
        self::assertStringContainsString(ObservationId::CryptographicCapability->value, $verdict->summary);
    }

    #[Test]
    public function corroborationIsNamedAsCorroborationAndNotAsProof(): void
    {
        $requirement = ControlRequirement::of(
            required: [RequiredFact::contributing(ObservationId::TokenVaultPersistence, [self::VAULT_FIX])],
            supporting: [ObservationId::MfaSubsystemResolved],
        );

        $verdict = ProbeVerdict::reach($requirement, self::deployment(ObservationGrade::Measured, true));

        self::assertSame(ControlOutcome::Satisfied, $verdict->outcome);
        self::assertStringContainsString('Corroborated, and not proved, by', $verdict->summary);
    }

    // --- Partial and the essential/contributing distinction -------------------

    #[Test]
    public function amissingContributingFactLeavesTheRestStanding(): void
    {
        $evidence = self::deployment(ObservationGrade::Measured, true, [
            ObservationId::CryptographicCapability->value => [ObservationGrade::Measured, false],
        ]);

        $verdict = ProbeVerdict::reach(self::twoFacts(), $evidence);

        self::assertSame(ControlOutcome::Partial, $verdict->outcome);
        self::assertSame([self::CRYPTO_FIX], $verdict->remediations);
    }

    /**
     * A vault that lost its mappings is not a weaker vault, so its absence is a
     * gap however strong the cryptography beside it was observed to be.
     */
    #[Test]
    public function amissingEssentialFactIsAGapWhateverElseHolds(): void
    {
        $requirement = ControlRequirement::of([
            RequiredFact::essential(
                ObservationId::TokenVaultPersistence,
                [self::VAULT_FIX],
                'PAN tokens are not held in a store that survives a restart',
            ),
            RequiredFact::contributing(ObservationId::CryptographicCapability, [self::CRYPTO_FIX]),
        ]);

        $evidence = self::deployment(ObservationGrade::Measured, true, [
            ObservationId::TokenVaultPersistence->value => [ObservationGrade::Measured, false],
        ]);

        $verdict = ProbeVerdict::reach($requirement, $evidence);

        self::assertSame(ControlOutcome::Unsatisfied, $verdict->outcome);
        self::assertStringContainsString('survives a restart', $verdict->summary);
    }

    /**
     * A gap must always say how to close it, and it must always name what was
     * seen instead — the report is otherwise a complaint.
     */
    #[Test]
    public function everyGapCitesTheObservationAndNamesARemediation(): void
    {
        $verdict = ProbeVerdict::reach(self::twoFacts(), self::deployment(ObservationGrade::Measured, false));

        self::assertSame(ControlOutcome::Unsatisfied, $verdict->outcome);
        self::assertStringContainsString(ObservationId::TokenVaultPersistence->value, $verdict->summary);
        self::assertNotSame([], $verdict->remediations);
    }

    // --- Not applicable -------------------------------------------------------

    #[Test]
    public function anOperatorMayScopeASubjectOutAndTheAssertionIsReproduced(): void
    {
        $requirement = ControlRequirement::of(
            required: [RequiredFact::contributing(ObservationId::TokenVaultPersistence, [self::VAULT_FIX])],
            scope: ObservationId::ScopeStoresCardholderData,
        );

        $evidence = self::deployment(ObservationGrade::Measured, true, [
            ObservationId::ScopeStoresCardholderData->value => [ObservationGrade::Asserted, false],
        ]);

        $verdict = ProbeVerdict::reach($requirement, $evidence);

        self::assertSame(ControlOutcome::NotApplicable, $verdict->outcome);
        self::assertCount(1, $verdict->evidence);
        self::assertSame(ObservationGrade::Asserted, $verdict->evidence[0]->grade);
    }

    /**
     * No probe can know a deployment is out of scope, so a scope id that resolves
     * to anything but an operator assertion is a defect, reported as one.
     */
    #[Test]
    public function codeCannotScopeOutItsOwnControl(): void
    {
        $requirement = ControlRequirement::of(
            required: [RequiredFact::contributing(ObservationId::TokenVaultPersistence, [self::VAULT_FIX])],
            scope: ObservationId::MasterKeyResolved,
        );

        $this->expectException(InadmissibleEvidenceException::class);
        $this->expectExceptionMessageMatches('/Only an operator scope assertion/');

        (void) ProbeVerdict::reach($requirement, self::deployment(ObservationGrade::Measured, false));
    }

    // --- Fixtures -------------------------------------------------------------

    private static function twoFacts(): ControlRequirement
    {
        return ControlRequirement::of([
            RequiredFact::contributing(ObservationId::TokenVaultPersistence, [self::VAULT_FIX]),
            RequiredFact::contributing(ObservationId::CryptographicCapability, [self::CRYPTO_FIX]),
        ]);
    }

    /**
     * A whole deployment stated in one line: a base grade and presence for every
     * fact, with named exceptions.
     *
     * @param array<string, array{ObservationGrade, bool}> $overrides keyed by ObservationId value
     */
    private static function deployment(
        ObservationGrade $grade,
        bool $present,
        array $overrides = [],
    ): ControlEvidence {
        $observations = [];

        foreach (ObservationId::cases() as $id) {
            $isScope = in_array($id, [
                ObservationId::ScopeStoresCardholderData,
                ObservationId::ScopeProcessesHealthData,
                ObservationId::ScopeProcessesPersonalData,
            ], true);

            [$factGrade, $factPresent] = $overrides[$id->value]
                ?? [$isScope ? ObservationGrade::Asserted : $grade, $isScope || $present];

            $observations[] = SyntheticObservation::of(
                $id,
                $factGrade,
                $factPresent,
                sprintf('deployment fixture: %s', $id->value),
            );
        }

        return ReflectedVocabulary::evidence(...$observations);
    }
}
