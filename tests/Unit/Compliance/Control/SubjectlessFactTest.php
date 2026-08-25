<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Compliance\Control;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Compliance\Control\ControlOutcome;
use Pulsar\Compliance\Control\ControlRequirement;
use Pulsar\Compliance\Control\InadmissibleEvidenceException;
use Pulsar\Compliance\Control\ObservationGrade;
use Pulsar\Compliance\Control\ObservationId;
use Pulsar\Compliance\Control\ProbeVerdict;
use Pulsar\Compliance\Control\RequiredFact;
use Pulsar\Compliance\Control\SubjectAbsence;
use Pulsar\Tests\Support\Compliance\EvidenceFixture;
use Pulsar\Tests\Support\Compliance\SyntheticObservation;
use ReflectionMethod;

/**
 * What the decision table does with a fact this deployment has no subject for.
 *
 * Three rules, and the middle one is the whole reason the state exists rather
 * than being folded into `present: false`:
 *
 *   1. every required fact subjectless ... NotApplicable — not a gap, not a pass,
 *      and out of the coverage denominator entirely;
 *   2. one subjectless beside one observed ... the observed one still decides, so
 *      a control is never failed for lacking a thing it does not need;
 *   3. one subjectless beside one merely configured ... still Unsatisfied, so a
 *      live requirement is never excused by an absent neighbour.
 *
 * Rule 3 is the one an escape hatch would break. If subjectlessness could
 * propagate, "we have no database" would retire the session-encryption
 * requirement standing next to it, and the vocabulary meant to cure the
 * absence-is-presence defect would have reintroduced it one level up.
 */
#[CoversClass(ProbeVerdict::class)]
#[CoversClass(SubjectAbsence::class)]
final class SubjectlessFactTest extends TestCase
{
    /** @var non-empty-string */
    private const string FIX = 'Configure a database and require TLS on it.';

    #[Test]
    public function aControlWhoseEveryRequirementHasNoSubjectIsNotApplicable(): void
    {
        $verdict = ProbeVerdict::reach(
            ControlRequirement::of([
                RequiredFact::essential(ObservationId::DatabaseTransportEncrypted, [self::FIX]),
            ]),
            EvidenceFixture::nothingObservedWithNoSubjectFor(
                ObservationId::DatabaseTransportEncrypted,
            )->gather(),
        );

        self::assertSame(ControlOutcome::NotApplicable, $verdict->outcome);
        self::assertFalse($verdict->outcome->isGap(), 'A control with no subject is not a failure.');
        self::assertFalse(
            $verdict->outcome->countsTowardCoverage(),
            'Nor is it a pass: it must leave the denominator rather than inflate the rate.',
        );
        self::assertStringContainsString('has no subject for', $verdict->summary);
        self::assertStringContainsString('database_transport_encrypted', $verdict->summary);
    }

    /**
     * Not applicable is reported with what was looked for, so an assessor can
     * challenge the enumeration rather than being handed a bare verdict.
     */
    #[Test]
    public function theNotApplicableVerdictNamesTheEnumerationThatCameBackEmpty(): void
    {
        $verdict = ProbeVerdict::reach(
            ControlRequirement::of([
                RequiredFact::contributing(ObservationId::DatabaseTransportEncrypted, [self::FIX]),
            ]),
            EvidenceFixture::everythingObservedWithNoSubjectFor(
                ObservationId::DatabaseTransportEncrypted,
            )->gather(),
        );

        self::assertSame(ControlOutcome::NotApplicable, $verdict->outcome);
        self::assertNotSame([], $verdict->evidence);
        self::assertStringContainsString('has no database_transport_encrypted at all', $verdict->summary);
    }

    #[Test]
    public function anObservedRequirementStillDecidesWhenASubjectlessOneStandsBesideIt(): void
    {
        $verdict = ProbeVerdict::reach(
            ControlRequirement::of([
                RequiredFact::contributing(ObservationId::DatabaseTransportEncrypted, [self::FIX]),
                RequiredFact::essential(ObservationId::CryptographicCapability, [self::FIX]),
            ]),
            EvidenceFixture::everythingObservedWithNoSubjectFor(
                ObservationId::DatabaseTransportEncrypted,
            )->gather(),
        );

        self::assertSame(
            ControlOutcome::Satisfied,
            $verdict->outcome,
            'A deployment must not be failed for lacking a subject the control does not need '
                . 'when the requirement that remains was observed working.',
        );
        self::assertStringNotContainsString(
            'database_transport_encrypted',
            $verdict->summary,
            'A fact that decided nothing must not appear in the sentence claiming the control.',
        );
    }

    /**
     * The escape-hatch test. The live requirement is present but only Declared —
     * configured, never observed — which is the case the whole design exists to
     * catch. A subjectless neighbour must not convert it into "not applicable".
     */
    #[Test]
    public function aSubjectlessFactNeverExcusesALiveRequirementBesideIt(): void
    {
        $verdict = ProbeVerdict::reach(
            ControlRequirement::of([
                RequiredFact::contributing(ObservationId::DatabaseTransportEncrypted, [self::FIX]),
                RequiredFact::essential(ObservationId::SessionEncryptionConfigured, [self::FIX]),
            ]),
            EvidenceFixture::nothingObservedWithNoSubjectFor(
                ObservationId::DatabaseTransportEncrypted,
            )->gather(),
        );

        self::assertSame(ControlOutcome::Unsatisfied, $verdict->outcome);
        self::assertTrue($verdict->outcome->isGap());
        self::assertTrue($verdict->outcome->countsTowardCoverage());
    }

    /**
     * The invariant kept on the value rather than only in the decision table, for
     * the reason `satisfied()` keeps its own: a second table added later must not
     * be able to retire a live requirement by calling it inapplicable.
     */
    #[Test]
    public function theNotApplicableFactoryRefusesAFactThatStillHasASubject(): void
    {
        $method = new ReflectionMethod(ProbeVerdict::class, 'withoutSubject');

        self::assertTrue($method->isPrivate(), 'No caller may choose this outcome.');

        $subjected = SyntheticObservation::of(
            ObservationId::SessionEncryptionConfigured,
            ObservationGrade::Declared,
            true,
            'session encryption is configured',
        );

        $this->expectException(InadmissibleEvidenceException::class);
        $this->expectExceptionMessageMatches('/while a required fact still has a subject/');

        $method->invoke(null, 'Not applicable.', [$subjected], [$subjected]);
    }

    #[Test]
    public function aSubjectlessObservationIsNeitherPresentNorAdmissible(): void
    {
        $observation = SyntheticObservation::withoutSubject(
            ObservationId::DatabaseTransportEncrypted,
            'There is no networked connection.',
        );

        self::assertFalse($observation->subjectExists);
        self::assertFalse($observation->present);
        self::assertFalse($observation->isAdmissibleAsProof());
    }
}
