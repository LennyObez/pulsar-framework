<?php

declare(strict_types=1);

namespace Pulsar\Extension\AiGovernance\Tests\Unit\Oversight;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\PdoConnection;
use Pulsar\Extension\AiGovernance\Contracts\HumanOversightInterface;
use Pulsar\Extension\AiGovernance\Exception\AiGovernanceException;
use Pulsar\Extension\AiGovernance\Internal\Store\DbHumanOversight;
use Pulsar\Extension\AiGovernance\Internal\Store\InMemoryHumanOversight;
use Pulsar\Extension\AiGovernance\Oversight\OversightAction;
use Pulsar\Extension\AiGovernance\Oversight\OversightAssignment;
use Pulsar\Extension\AiGovernance\Oversight\OversightCapability;
use Pulsar\Extension\AiGovernance\Oversight\OversightIntervention;
use Pulsar\Extension\AiGovernance\Tests\Support\AiGovernanceSchema;

use function array_map;

/**
 * EU AI Act Article 14 and Article 26(2): who oversees a system, and what they did.
 *
 * BOTH SHIPPED STORES RUN EVERY CONTRACT CASE, through the same data provider.
 * The contract documents its invariants as requirements of any implementation —
 * an intervention is refused for an unassigned person, and refused when the
 * assignment does not confer the capacity the action exercises — and the two
 * stores hold those checks in their own bodies rather than sharing a base class.
 * Running one set of cases against both is what stops the durable store and the
 * development store drifting into two different contracts, which is the failure
 * mode a shared parent would hide rather than prevent.
 *
 * The durability cases run against the database store alone, and read through a
 * SECOND INSTANCE, because that is the only thing separating a store that retains
 * from one that remembers.
 */
#[CoversClass(InMemoryHumanOversight::class)]
#[CoversClass(DbHumanOversight::class)]
#[CoversClass(OversightAssignment::class)]
#[CoversClass(OversightIntervention::class)]
#[CoversClass(OversightAction::class)]
#[CoversClass(OversightCapability::class)]
final class HumanOversightTest extends TestCase
{
    private PdoConnection $connection;

    protected function setUp(): void
    {
        $this->connection = AiGovernanceSchema::connection();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function stores(): iterable
    {
        yield 'the durable store, which is the default' => [DbHumanOversight::class];
        yield 'the development store' => [InMemoryHumanOversight::class];
    }

    // --- The arrangement ------------------------------------------------------

    #[Test]
    #[DataProvider('stores')]
    public function aSystemWithNobodyAssignedIsNotOverseen(string $store): void
    {
        self::assertFalse($this->store($store)->isOverseen('model-1'));
    }

    #[Test]
    #[DataProvider('stores')]
    public function oneAssignmentDischargesArticle26Paragraph2(string $store): void
    {
        $oversight = $this->store($store);
        $oversight->assign($this->assignment());

        // Computed from what is on record, never written. This one person holds
        // both Article 14(4) action capacities, which is the simplest arrangement
        // that discharges the duty — and the assignment could not exist at all
        // without a stated competence and a stated authority behind them.
        self::assertTrue($oversight->isOverseen('model-1'));
    }

    #[Test]
    #[DataProvider('stores')]
    public function theAssignmentReadsBackWithTheBasesThatJustifyIt(string $store): void
    {
        $oversight = $this->store($store);
        $oversight->assign($this->assignment());

        $assignments = $oversight->assignmentsFor('model-1');

        self::assertCount(1, $assignments);
        self::assertSame('clinician-7', $assignments[0]->overseerId);
        self::assertSame(
            'Registered clinician, completed the model-specific training in 2026.',
            $assignments[0]->competenceBasis,
        );
        self::assertSame(
            'Named in the clinical safety policy as able to suspend the system.',
            $assignments[0]->authorityBasis,
        );
        self::assertTrue($assignments[0]->confers(OversightCapability::InterveneOrInterrupt));
    }

    #[Test]
    #[DataProvider('stores')]
    public function reassigningTheSamePersonReplacesTheirArrangement(string $store): void
    {
        $oversight = $this->store($store);
        $oversight->assign($this->assignment());
        $oversight->assign($this->assignment(authority: 'Revised mandate under the 2027 policy.'));

        $assignments = $oversight->assignmentsFor('model-1');

        self::assertCount(1, $assignments, 'a person holds one set of capacities over one system');
        self::assertSame('Revised mandate under the 2027 policy.', $assignments[0]->authorityBasis);
    }

    #[Test]
    #[DataProvider('stores')]
    public function withdrawingTheLastOverseerLeavesTheSystemUnoverseen(string $store): void
    {
        $oversight = $this->store($store);
        $oversight->assign($this->assignment());
        $oversight->withdraw('model-1', 'clinician-7');

        // Article 26(2) is a continuing duty. A deployment whose only named
        // overseer left the organisation must stop reporting that the system is
        // overseen.
        self::assertFalse($oversight->isOverseen('model-1'));
        self::assertSame([], $oversight->assignmentsFor('model-1'));
    }

    #[Test]
    #[DataProvider('stores')]
    public function withdrawingSomeoneWhoWasNeverAssignedIsNotAnError(string $store): void
    {
        $oversight = $this->store($store);
        $oversight->withdraw('model-1', 'nobody');

        self::assertFalse($oversight->isOverseen('model-1'));
    }

    #[Test]
    #[DataProvider('stores')]
    public function oversightIsPerSystem(string $store): void
    {
        $oversight = $this->store($store);
        $oversight->assign($this->assignment());

        self::assertTrue($oversight->isOverseen('model-1'));
        self::assertFalse($oversight->isOverseen('model-2'));
    }

    // --- The evidence ---------------------------------------------------------

    #[Test]
    #[DataProvider('stores')]
    public function anExerciseOfOversightIsRecorded(string $store): void
    {
        $oversight = $this->store($store);
        $oversight->assign($this->assignment());
        $oversight->recordIntervention($this->intervention());

        $recorded = $oversight->interventionsFor('model-1');

        self::assertCount(1, $recorded);
        self::assertSame(OversightAction::ReversedOutput, $recorded[0]->action);
        self::assertSame('decision-99', $recorded[0]->decisionId);
        self::assertSame(
            'The applicant produced documentation the model had no sight of.',
            $recorded[0]->rationale,
        );
    }

    #[Test]
    #[DataProvider('stores')]
    public function anOverrideByAnUnassignedPersonIsRefused(string $store): void
    {
        $oversight = $this->store($store);

        $this->expectException(AiGovernanceException::class);
        $this->expectExceptionMessageMatches('/Article 26\(2\)/');

        // Not evidence that the oversight arrangement works: evidence that
        // somebody touched the system.
        $oversight->recordIntervention($this->intervention());
    }

    #[Test]
    #[DataProvider('stores')]
    public function anOverrideExercisingACapacityTheAssignmentWithholdsIsRefused(string $store): void
    {
        $oversight = $this->store($store);

        // A reviewer who may disregard a recommendation and may not stop the
        // service. A real and common arrangement, and this person halting the
        // system is a capacity nobody granted them.
        $oversight->assign($this->assignment(
            overseer: 'reviewer-3',
            capabilities: [OversightCapability::DisregardOrReverseOutput],
        ));

        $this->expectException(AiGovernanceException::class);
        $this->expectExceptionMessageMatches('/Article 14\(4\)/');

        $oversight->recordIntervention(new OversightIntervention(
            interventionId: 'i-2',
            modelId: 'model-1',
            overseerId: 'reviewer-3',
            action: OversightAction::InterruptedOperation,
            rationale: 'Halted the queue.',
            occurredAt: new DateTimeImmutable('2026-06-01T09:00:00+00:00'),
        ));
    }

    #[Test]
    #[DataProvider('stores')]
    public function aRoomOfReviewersWhoCannotStopTheSystemIsNotOversight(string $store): void
    {
        $oversight = $this->store($store);
        $oversight->assign($this->assignment(
            overseer: 'reviewer-3',
            capabilities: [OversightCapability::DisregardOrReverseOutput],
        ));
        $oversight->assign($this->assignment(
            overseer: 'reviewer-4',
            capabilities: [
                OversightCapability::DisregardOrReverseOutput,
                OversightCapability::UnderstandAndMonitor,
            ],
        ));

        // Two competent, authorised, assigned people — and Article 14(4)(e) is
        // conferred on nobody. A check that counted assignments would call this
        // oversight; this one asks what the arrangement enables.
        self::assertCount(2, $oversight->assignmentsFor('model-1'));
        self::assertFalse($oversight->isOverseen('model-1'));
    }

    #[Test]
    #[DataProvider('stores')]
    public function twoPeopleTogetherCanDischargeWhatNeitherHoldsAlone(string $store): void
    {
        $oversight = $this->store($store);
        $oversight->assign($this->assignment(
            overseer: 'reviewer-3',
            capabilities: [OversightCapability::DisregardOrReverseOutput],
        ));

        self::assertFalse($oversight->isOverseen('model-1'));

        $oversight->assign($this->assignment(
            overseer: 'duty-manager-1',
            capabilities: [OversightCapability::InterveneOrInterrupt],
        ));

        // Article 14(4) asks that the oversight MEASURES enable both actions, not
        // that one individual can take both.
        self::assertTrue($oversight->isOverseen('model-1'));
    }

    #[Test]
    #[DataProvider('stores')]
    public function theRegisterIsOldestFirst(string $store): void
    {
        $oversight = $this->store($store);
        $oversight->assign($this->assignment());

        $oversight->recordIntervention($this->intervention(id: 'i-late', at: '2026-06-03T09:00:00+00:00'));
        $oversight->recordIntervention($this->intervention(id: 'i-early', at: '2026-06-01T09:00:00+00:00'));

        self::assertSame(
            ['i-early', 'i-late'],
            array_map(
                static fn(OversightIntervention $i): string => $i->interventionId,
                $oversight->interventionsFor('model-1'),
            ),
        );
    }

    #[Test]
    #[DataProvider('stores')]
    public function withdrawingAMandateDoesNotEraseWhatWasDoneUnderIt(string $store): void
    {
        $oversight = $this->store($store);
        $oversight->assign($this->assignment());
        $oversight->recordIntervention($this->intervention());

        $oversight->withdraw('model-1', 'clinician-7');

        // What a person did while they held the authority happened. A register
        // that emptied when a mandate was revoked would be evidence of nothing.
        self::assertCount(1, $oversight->interventionsFor('model-1'));
        self::assertFalse($oversight->isOverseen('model-1'));
    }

    // --- Durability -----------------------------------------------------------

    #[Test]
    public function theAssignmentSurvivesTheStoreThatRecordedIt(): void
    {
        new DbHumanOversight($this->connection)->assign($this->assignment());

        $back = new DbHumanOversight($this->connection)->assignmentsFor('model-1');

        self::assertCount(1, $back);
        self::assertSame('clinician-7', $back[0]->overseerId);
        self::assertSame(
            OversightCapability::exercisable(),
            $back[0]->capabilities,
            'the capacities Article 14(4)(d) and (e) require must come back as themselves',
        );

        // The instant the person was assigned, not the instant the row was
        // written: a store stamping its own clock would record when the record
        // was made and call it when the assignment began.
        self::assertSame('2026-01-01', $back[0]->assignedAt->format('Y-m-d'));
    }

    #[Test]
    public function theInterventionRegisterSurvivesTheStoreThatWroteIt(): void
    {
        $store = new DbHumanOversight($this->connection);
        $store->assign($this->assignment());
        $store->recordIntervention($this->intervention());

        $back = new DbHumanOversight($this->connection)->interventionsFor('model-1');

        self::assertCount(1, $back);
        self::assertSame('decision-99', $back[0]->decisionId);
        self::assertSame(OversightAction::ReversedOutput, $back[0]->action);
    }

    #[Test]
    public function theDevelopmentStoreLosesBothWithTheInstanceThatHeldThem(): void
    {
        $store = new InMemoryHumanOversight();
        $store->assign($this->assignment());
        $store->recordIntervention($this->intervention());

        self::assertTrue($store->isOverseen('model-1'), 'the writer itself can still see it');

        $second = new InMemoryHumanOversight();

        self::assertFalse($second->isOverseen('model-1'));
        self::assertSame(
            [],
            $second->interventionsFor('model-1'),
            'and the register a contested decision would be answered from is gone with it',
        );
    }

    #[Test]
    public function anActionOutsideArticle14IsACorruptRecord(): void
    {
        $store = new DbHumanOversight($this->connection);
        $store->assign($this->assignment());

        $this->connection->execute(
            'INSERT INTO ai_oversight_interventions'
                . ' (intervention_id, model_id, overseer_id, action, rationale, decision_id, occurred_at)'
                . " VALUES ('i-bad', 'model-1', 'clinician-7', 'shrugged', 'No reason.', NULL,"
                . " '2026-06-01 09:00:00')",
        );

        $this->expectException(AiGovernanceException::class);
        $this->expectExceptionMessageMatches('/action/');

        (void) $store->interventionsFor('model-1');
    }

    #[Test]
    public function aCapacityOutsideArticle14IsACorruptRecord(): void
    {
        $this->connection->execute(
            'INSERT INTO ai_oversight_assignments'
                . ' (model_id, overseer_id, competence_basis, authority_basis, capabilities, assigned_at)'
                . " VALUES ('model-1', 'clinician-7', 'Trained.', 'Mandated.', '[\"telepathy\"]',"
                . " '2026-01-01 00:00:00')",
        );

        $this->expectException(AiGovernanceException::class);
        $this->expectExceptionMessageMatches('/capabilities/');

        (void) new DbHumanOversight($this->connection)->assignmentsFor('model-1');
    }

    // --- What the types refuse ------------------------------------------------

    #[Test]
    public function anAssignmentWithoutAStatedCompetenceIsRefused(): void
    {
        $this->expectException(AiGovernanceException::class);
        $this->expectExceptionMessageMatches('/competence/');

        (void) $this->assignment(competence: '   ');
    }

    #[Test]
    public function anAssignmentWithoutAStatedAuthorityIsRefused(): void
    {
        // Separate from competence because Article 26(2) lists them separately: a
        // clinician can be wholly competent to judge a recommendation and hold no
        // mandate to stop the system making them.
        $this->expectException(AiGovernanceException::class);
        $this->expectExceptionMessageMatches('/authority/');

        (void) $this->assignment(authority: '');
    }

    #[Test]
    public function anAssignmentThatConfersNothingActionableIsRefused(): void
    {
        // Only the understanding-side points of Article 14(4). This person sees
        // the system go wrong and has nothing they may do about it, which is an
        // observer rather than an overseer.
        $this->expectException(AiGovernanceException::class);
        $this->expectExceptionMessageMatches('/Article 14\(4\)\(d\) and \(e\)/');

        (void) $this->assignment(capabilities: [
            OversightCapability::UnderstandAndMonitor,
            OversightCapability::CorrectlyInterpretOutput,
        ]);
    }

    #[Test]
    public function anAssignmentConferringNoCapacityAtAllIsRefused(): void
    {
        $this->expectException(AiGovernanceException::class);

        (void) $this->assignment(capabilities: []);
    }

    /**
     * One person holding only one of the two action capacities is a valid
     * assignment, and the arrangement it belongs to may still be incomplete.
     *
     * The type refuses an assignment that grants nothing; judging whether
     * Article 26(2) is discharged is the store's job, over every assignment for
     * the system, because that is the level the Act's question is asked at.
     */
    #[Test]
    public function onePersonNeedNotHoldEveryCapacity(): void
    {
        $assignment = $this->assignment(capabilities: [OversightCapability::DisregardOrReverseOutput]);

        self::assertTrue($assignment->confers(OversightCapability::DisregardOrReverseOutput));
        self::assertFalse($assignment->confers(OversightCapability::InterveneOrInterrupt));
    }

    #[Test]
    public function anAssignmentNamingNoPersonIsRefused(): void
    {
        $this->expectException(AiGovernanceException::class);
        $this->expectExceptionMessageMatches('/natural person/');

        (void) $this->assignment(overseer: ' ');
    }

    #[Test]
    public function anInterventionWithNoStatedReasonIsRefused(): void
    {
        $this->expectException(AiGovernanceException::class);
        $this->expectExceptionMessageMatches('/Article 22\(3\)/');

        (void) $this->intervention(rationale: '');
    }

    #[Test]
    public function anInterventionWithABlankDecisionIdIsRefused(): void
    {
        // Null is the honest value for an override that concerned no single
        // decision; an empty string is a broken link dressed as an absent one.
        $this->expectException(AiGovernanceException::class);

        (void) new OversightIntervention(
            interventionId: 'i-1',
            modelId: 'model-1',
            overseerId: 'clinician-7',
            action: OversightAction::InterruptedOperation,
            rationale: 'Halted the queue.',
            occurredAt: new DateTimeImmutable('2026-06-01T09:00:00+00:00'),
            decisionId: '  ',
        );
    }

    #[Test]
    public function eachActionNamesTheCapacityItExercises(): void
    {
        self::assertSame(
            OversightCapability::InterveneOrInterrupt,
            OversightAction::InterruptedOperation->exercises(),
        );

        foreach (
            [
                OversightAction::DeclinedToUse,
                OversightAction::DisregardedOutput,
                OversightAction::ReversedOutput,
            ] as $action
        ) {
            self::assertSame(OversightCapability::DisregardOrReverseOutput, $action->exercises());
        }
    }

    #[Test]
    public function onlyTheTwoSystemSideCapacitiesMustBeExercisable(): void
    {
        // The other three are properties of the person's training. A framework
        // that required them to be "exercisable" would be scoring a human being's
        // comprehension from a database row.
        self::assertTrue(OversightCapability::DisregardOrReverseOutput->mustBeExercisable());
        self::assertTrue(OversightCapability::InterveneOrInterrupt->mustBeExercisable());
        self::assertFalse(OversightCapability::UnderstandAndMonitor->mustBeExercisable());
        self::assertFalse(OversightCapability::RemainAwareOfAutomationBias->mustBeExercisable());
        self::assertFalse(OversightCapability::CorrectlyInterpretOutput->mustBeExercisable());
    }

    // --- Fixtures -------------------------------------------------------------

    /**
     * @param class-string<HumanOversightInterface> $store
     */
    private function store(string $store): HumanOversightInterface
    {
        return $store === DbHumanOversight::class
            ? new DbHumanOversight($this->connection)
            : new InMemoryHumanOversight();
    }

    /**
     * @param list<OversightCapability>|null $capabilities
     */
    private function assignment(
        string $overseer = 'clinician-7',
        string $competence = 'Registered clinician, completed the model-specific training in 2026.',
        string $authority = 'Named in the clinical safety policy as able to suspend the system.',
        ?array $capabilities = null,
    ): OversightAssignment {
        return new OversightAssignment(
            modelId: 'model-1',
            overseerId: $overseer,
            competenceBasis: $competence,
            authorityBasis: $authority,
            capabilities: $capabilities ?? OversightCapability::exercisable(),
            assignedAt: new DateTimeImmutable('2026-01-01T00:00:00+00:00'),
        );
    }

    private function intervention(
        string $id = 'i-1',
        string $rationale = 'The applicant produced documentation the model had no sight of.',
        string $at = '2026-06-01T09:00:00+00:00',
    ): OversightIntervention {
        return new OversightIntervention(
            interventionId: $id,
            modelId: 'model-1',
            overseerId: 'clinician-7',
            action: OversightAction::ReversedOutput,
            rationale: $rationale,
            occurredAt: new DateTimeImmutable($at),
            decisionId: 'decision-99',
        );
    }
}
