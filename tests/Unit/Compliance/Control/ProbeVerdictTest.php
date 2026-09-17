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
use Pulsar\Compliance\Control\ControlSubject;
use Pulsar\Compliance\Control\InadmissibleEvidenceException;
use Pulsar\Compliance\Control\ObservationGrade;
use Pulsar\Compliance\Control\ObservationId;
use Pulsar\Compliance\Control\ProbeVerdict;
use Pulsar\Compliance\Control\RequiredFact;
use Pulsar\Tests\Support\Compliance\ReflectedVocabulary;
use Pulsar\Tests\Support\Compliance\SyntheticObservation;
use ReflectionMethod;

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
#[CoversClass(ControlSubject::class)]
#[CoversClass(InadmissibleEvidenceException::class)]
final class ProbeVerdictTest extends TestCase
{
    private const string VAULT_FIX = 'Configure a durable token store.';

    private const string EXERCISE_FIX = 'Run a value through the vault.';

    /**
     * The estate every requirement in this file is about.
     *
     * Both facts the fixture uses — the vault's persistence and the vault
     * rendering a value unreadable — interrogate the PAN estate, so the estate
     * join in {@see ProbeVerdict::reach()} is satisfied throughout and every
     * property below is about the grade rules it was written for. The join has
     * its own tests at the end of this file, where a fact about another estate is
     * offered deliberately.
     */
    private const ControlSubject SUBJECT = ControlSubject::CardholderData;

    // --- Satisfied -----------------------------------------------------------

    #[Test]
    public function satisfiedNeedsAFactObservedBehavingRatherThanConfigured(): void
    {
        $verdict = ProbeVerdict::reach(self::twoFacts(), self::deployment(ObservationGrade::Measured, true), self::SUBJECT);

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
        $verdict = ProbeVerdict::reach(self::twoFacts(), self::deployment($grade, true), self::SUBJECT);

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
                RequiredFact::contributing(ObservationId::TokenVaultRendersUnreadable, [self::EXERCISE_FIX]),
            ],
            supporting: [ObservationId::MfaSubsystemResolved],
        );

        // Everything the control requires is configured and unobserved; the one
        // fact observed behaving is the supporting one.
        $evidence = self::deployment(ObservationGrade::Declared, true, [
            ObservationId::MfaSubsystemResolved->value => [ObservationGrade::Measured, true],
        ]);

        $verdict = ProbeVerdict::reach($requirement, $evidence, self::SUBJECT);

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
        $verdict = ProbeVerdict::reach(self::twoFacts(), self::deployment(ObservationGrade::Measured, true), self::SUBJECT);

        self::assertStringContainsString(ObservationId::TokenVaultPersistence->value, $verdict->summary);
        self::assertStringContainsString(ObservationId::TokenVaultRendersUnreadable->value, $verdict->summary);
    }

    #[Test]
    public function corroborationIsNamedAsCorroborationAndNotAsProof(): void
    {
        $requirement = ControlRequirement::of(
            required: [RequiredFact::contributing(ObservationId::TokenVaultPersistence, [self::VAULT_FIX])],
            supporting: [ObservationId::MfaSubsystemResolved],
        );

        $verdict = ProbeVerdict::reach($requirement, self::deployment(ObservationGrade::Measured, true), self::SUBJECT);

        self::assertSame(ControlOutcome::Satisfied, $verdict->outcome);
        self::assertStringContainsString('Corroborated, and not proved, by', $verdict->summary);
    }

    // --- Partial and the essential/contributing distinction -------------------

    #[Test]
    public function amissingContributingFactLeavesTheRestStanding(): void
    {
        $evidence = self::deployment(ObservationGrade::Measured, true, [
            ObservationId::TokenVaultRendersUnreadable->value => [ObservationGrade::Measured, false],
        ]);

        $verdict = ProbeVerdict::reach(self::twoFacts(), $evidence, self::SUBJECT);

        self::assertSame(ControlOutcome::Partial, $verdict->outcome);
        self::assertSame([self::EXERCISE_FIX], $verdict->remediations);
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
            RequiredFact::contributing(ObservationId::TokenVaultRendersUnreadable, [self::EXERCISE_FIX]),
        ]);

        $evidence = self::deployment(ObservationGrade::Measured, true, [
            ObservationId::TokenVaultPersistence->value => [ObservationGrade::Measured, false],
        ]);

        $verdict = ProbeVerdict::reach($requirement, $evidence, self::SUBJECT);

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
        $verdict = ProbeVerdict::reach(self::twoFacts(), self::deployment(ObservationGrade::Measured, false), self::SUBJECT);

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

        $verdict = ProbeVerdict::reach($requirement, $evidence, self::SUBJECT);

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

        (void) ProbeVerdict::reach($requirement, self::deployment(ObservationGrade::Measured, false), self::SUBJECT);
    }

    // --- The estate joins -----------------------------------------------------

    /**
     * The A2 rule: a measurement of something else is not evidence about this
     * control, however strong it is.
     *
     * The two facts here are both MEASURED and both PRESENT, so every grade rule
     * in this file is satisfied and only the estate separates them. The control
     * regulates the PAN estate; the deployment ran an HMAC recomputation over the
     * compliance evidence register. That is the shape of the finding that carried
     * eleven audit controls across eleven standards.
     */
    #[Test]
    public function aMeasurementOfAnotherEstateCannotCarryTheControl(): void
    {
        $requirement = ControlRequirement::of([
            RequiredFact::contributing(ObservationId::AuditChainVerified, [self::EXERCISE_FIX]),
        ]);

        $verdict = ProbeVerdict::reach(
            $requirement,
            self::deployment(ObservationGrade::Measured, true),
            ControlSubject::CardholderData,
        );

        self::assertSame(ControlOutcome::Unsatisfied, $verdict->outcome);
        self::assertStringContainsString('Something did run, and not on this control\'s estate', $verdict->summary);
        self::assertStringContainsString(ControlSubject::ComplianceEvidenceRegister->value, $verdict->summary);
    }

    /**
     * And the counter-assertion that keeps it honest: the identical requirement,
     * assessed for a control that IS about that estate, is satisfied. The rule
     * refuses a mismatch, not a fact.
     */
    #[Test]
    public function theSameMeasurementCarriesAControlAboutItsOwnEstate(): void
    {
        $requirement = ControlRequirement::of([
            RequiredFact::contributing(ObservationId::AuditChainVerified, [self::EXERCISE_FIX]),
        ]);

        $verdict = ProbeVerdict::reach(
            $requirement,
            self::deployment(ObservationGrade::Measured, true),
            ControlSubject::ComplianceEvidenceRegister,
        );

        self::assertSame(ControlOutcome::Satisfied, $verdict->outcome);
    }

    /**
     * A fact about a PART of the estate the control regulates does carry it. This
     * is the one place the vocabulary is not flat, and it is what stops HIPAA
     * 164.312(e)(1) from needing a fact literally named "data in transit".
     */
    #[Test]
    public function aFactAboutAPartOfTheEstateCarriesTheWhole(): void
    {
        $requirement = ControlRequirement::of([
            RequiredFact::contributing(ObservationId::DatabaseTransportEncrypted, [self::EXERCISE_FIX]),
        ]);

        $verdict = ProbeVerdict::reach(
            $requirement,
            self::deployment(ObservationGrade::Measured, true),
            ControlSubject::DataInTransit,
        );

        self::assertSame(ControlOutcome::Satisfied, $verdict->outcome);
    }

    /**
     * And not the other way round. A control about the database link alone is not
     * carried by a fact about every link there is, because the fact would then be
     * answering a broader question than the one asked.
     */
    #[Test]
    public function aFactAboutTheWholeEstateDoesNotCarryOneOfItsParts(): void
    {
        self::assertFalse(ControlSubject::DatabaseTransport->covers(ControlSubject::DataInTransit));
        self::assertTrue(ControlSubject::DataInTransit->covers(ControlSubject::DatabaseTransport));
    }

    /**
     * The A6 rule: an operator's claim about one estate cannot retire a control
     * about another. `scope.processes_personal_data = false` was retiring SOC 2
     * C1.2 and CC6.5, which are about confidential information.
     */
    #[Test]
    public function anAssertionAboutAnotherEstateCannotRetireTheControl(): void
    {
        $requirement = ControlRequirement::of(
            required: [RequiredFact::contributing(ObservationId::TokenVaultPersistence, [self::VAULT_FIX])],
            scope: ObservationId::ScopeProcessesPersonalData,
        );

        $evidence = self::deployment(ObservationGrade::Declared, true, [
            ObservationId::ScopeProcessesPersonalData->value => [ObservationGrade::Asserted, false],
        ]);

        $verdict = ProbeVerdict::reach($requirement, $evidence, ControlSubject::ConfidentialInformation);

        self::assertSame(
            ControlOutcome::Unsatisfied,
            $verdict->outcome,
            'An entity that processes no personal data still holds confidential information.',
        );
    }

    /**
     * The refusal is a property of the value as well as of the table: offering
     * {@see ProbeVerdict} a not-applicable verdict built on an unrelated assertion
     * throws, so a second decision table added later meets the same bar.
     */
    #[Test]
    public function theNotApplicableValueItselfRefusesAnUnrelatedAssertion(): void
    {
        $requirement = ControlRequirement::of(
            required: [RequiredFact::contributing(ObservationId::TokenVaultPersistence, [self::VAULT_FIX])],
            scope: ObservationId::ScopeProcessesPersonalData,
        );

        $evidence = self::deployment(ObservationGrade::Declared, true, [
            ObservationId::ScopeProcessesPersonalData->value => [ObservationGrade::Asserted, false],
        ]);

        // Reached through the table for a control that IS about personal data, the
        // same assertion retires the control — which is what makes the refusal
        // above a statement about the estate rather than about the assertion.
        $verdict = ProbeVerdict::reach($requirement, $evidence, ControlSubject::PersonalData);

        self::assertSame(ControlOutcome::NotApplicable, $verdict->outcome);
    }

    /**
     * The refusal is a property of the VALUE, not only of the table.
     *
     * `notApplicable()` is private, which Reflection ignores — so what stops a
     * second decision table from retiring a control on somebody else's scoping
     * claim is the check inside, and this reaches it directly. The same shape
     * {@see \Pulsar\Tests\Unit\Compliance\Control\OutcomeSealTest} uses for
     * `satisfied()`, for the same reason.
     */
    #[Test]
    public function thePrivateNotApplicableFactoryRefusesAnAssertionAboutAnotherEstate(): void
    {
        $factory = new ReflectionMethod(ProbeVerdict::class, 'notApplicable');

        self::assertTrue($factory->isPrivate(), 'No caller may choose this outcome.');

        $assertion = SyntheticObservation::of(
            ObservationId::ScopeProcessesPersonalData,
            ObservationGrade::Asserted,
            false,
            'config/compliance.php  scope.processes_personal_data = false',
        );

        $this->expectException(InadmissibleEvidenceException::class);
        $this->expectExceptionMessageMatches('/An assertion retires a control only when/');

        $factory->invokeArgs(null, ['Out of scope.', ControlSubject::ConfidentialInformation, $assertion]);
    }

    /**
     * The data classes are siblings on purpose: nesting them would let one line of
     * config silence standards it was never written about.
     */
    #[Test]
    public function aBroaderSoundingDataClassDoesNotContainANarrowerOne(): void
    {
        self::assertFalse(ControlSubject::PersonalData->covers(ControlSubject::HealthData));
        self::assertFalse(ControlSubject::PersonalData->covers(ControlSubject::CardholderData));
        self::assertFalse(ControlSubject::PersonalData->covers(ControlSubject::ConfidentialInformation));
    }

    // --- Fixtures -------------------------------------------------------------

    private static function twoFacts(): ControlRequirement
    {
        return ControlRequirement::of([
            RequiredFact::contributing(ObservationId::TokenVaultPersistence, [self::VAULT_FIX]),
            RequiredFact::contributing(ObservationId::TokenVaultRendersUnreadable, [self::EXERCISE_FIX]),
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
