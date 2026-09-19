<?php

declare(strict_types=1);

namespace Pulsar\Extension\AiGovernance\Contracts;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Extension\AiGovernance\Oversight\OversightAssignment;
use Pulsar\Extension\AiGovernance\Oversight\OversightIntervention;

/**
 * Who oversees an AI system, what they may do to it, and what they have done.
 *
 * ARTICLE 14 IS THE OBLIGATION AND IT WAS MODELLED NOWHERE. The extension held a
 * model registry, impact assessments, data provenance, explanations, monitoring
 * records and Article 50 declarations, and nothing at all for the requirement
 * that a high-risk system "be effectively overseen by natural persons during the
 * period in which it is in use". Article 14(4) is specific about what oversight
 * has to enable — the person must be able to decide not to use the system, to
 * disregard, override or reverse its output, and to intervene or interrupt it —
 * and Article 26(2) puts the matching duty on the deployer: assign that oversight
 * to natural persons with the necessary competence, training and authority.
 *
 * THE SPLIT THIS CONTRACT DRAWS IS BETWEEN ARRANGEMENT AND EVIDENCE, and it is
 * the whole design.
 *
 * An ASSIGNMENT is the arrangement for one person: a name, the stated basis of
 * their competence and of their authority, and the Article 14(4) capacities they
 * hold. {@see \Pulsar\Extension\AiGovernance\Oversight\OversightAssignment}
 * refuses to exist without all of it, and refuses in particular an assignment
 * conferring no capacity the person can ACT with — someone who may only watch is
 * not an overseer. It does not require one person to hold every capacity, because
 * Article 14(4) asks that the oversight MEASURES enable those actions: a reviewer
 * who may disregard a recommendation and a supervisor who may halt the service
 * are a normal arrangement, and {@see isOverseen()} is where the arrangement as a
 * whole is judged.
 *
 * An INTERVENTION is the evidence: a person actually disregarded, reversed or
 * halted something, on a stated date, for a stated reason. An arrangement nobody
 * has ever exercised is a policy; the register of interventions is the only thing
 * here that shows the arrangement works.
 *
 * HOW A DEPLOYMENT PROVES OVERSIGHT RATHER THAN ASSERTING IT. {@see isOverseen()}
 * is COMPUTED from assignments actually on record and is never written: there is
 * no method on this interface that sets an oversight status, and there is
 * deliberately none, because a status a caller can write is a status a caller can
 * write incorrectly. A deployment answers true because it recorded a competent,
 * authorised natural person against this system, and false the moment it did not.
 * {@see \Pulsar\Extension\AiGovernance\Internal\Gate\HighRiskObligationsGate}
 * asks this question of a deployer before a high-risk system reaches production,
 * so an unanswered Article 26(2) stops the deployment rather than appearing in a
 * report six months later.
 *
 * WHAT A FRAMEWORK CANNOT DO HERE, said plainly because the contract is the part
 * a deployer builds its own conformity on. This interface holds the record. It
 * does not build the stop button: Article 14(4)(e) asks for a control that brings
 * the system to a halt in a safe state, and what "safe state" means belongs to
 * the domain — a halted triage queue and a halted trade-surveillance model fail
 * in entirely different directions. It does not train anyone, so the three
 * Article 14(4) capacities that are properties of a person's understanding are
 * recorded and never scored. And it does not decide that oversight was EFFECTIVE,
 * which is a judgement about outcomes that Article 14(1) puts on the provider's
 * design and no store can reach. What it guarantees is narrower and checkable:
 * that the arrangement was declared in full, that it conferred the capacities the
 * Article names, and that every exercise of it was recorded where an assessor and
 * an affected person can both be shown it.
 * @api
 */
#[Api(since: '1.0.0-rc.12')]
interface HumanOversightInterface
{
    /**
     * Record that a natural person oversees this system.
     *
     * The assignment has already refused to exist if it was incomplete, so this
     * records rather than validates. Re-assigning the same person to the same
     * system replaces their assignment: a person holds one set of capacities over
     * one system, and two rows differing only in the basis text would leave a
     * reader unable to say which one is in force.
     */
    public function assign(OversightAssignment $assignment): void;

    /**
     * Withdraw one person's oversight of one system.
     *
     * Article 26(2) is a continuing duty, so it has to be possible to say that a
     * person no longer holds it — someone leaves, or a mandate is revoked. Without
     * a withdrawal path the register would only ever grow, and a deployment whose
     * only named overseer left the organisation would go on reporting that the
     * system is overseen. Withdrawing an assignment that was never made is not an
     * error: the end state is the one the caller asked for.
     *
     * Interventions are NOT removed with the assignment. What a person did while
     * they held the authority happened, and a register that could be emptied by
     * revoking a mandate would be evidence of nothing.
     *
     * @param non-empty-string $modelId
     * @param non-empty-string $overseerId
     */
    public function withdraw(string $modelId, string $overseerId): void;

    /**
     * Everyone currently assigned to oversee this system.
     *
     * @param non-empty-string $modelId
     *
     * @return list<OversightAssignment>
     */
    #[NoDiscard]
    public function assignmentsFor(string $modelId): array;

    /**
     * Whether Article 26(2) is discharged for this system.
     *
     * COMPUTED from the assignments on record, never stored and never set — there
     * is no method here that writes an oversight status, deliberately, because a
     * status a caller can write is one a caller can write incorrectly.
     *
     * True exactly when the assignments TAKEN TOGETHER confer every capacity
     * Article 14(4) requires to be exercisable: someone can disregard, override or
     * reverse the output (Article 14(4)(d)) AND someone can intervene in or
     * interrupt the operation (Article 14(4)(e)). It need not be the same person.
     * It is false when nobody is assigned, and false when everyone assigned may
     * only disregard and nobody may stop the system — which is the arrangement a
     * check counting assignments would have reported as oversight.
     *
     * Since an assignment cannot exist without a stated competence and a stated
     * authority, an answer of true also means those were declared for every person
     * counted towards it.
     *
     * @param non-empty-string $modelId
     */
    #[NoDiscard]
    public function isOverseen(string $modelId): bool;

    /**
     * Record that oversight was exercised.
     *
     * @throws \Pulsar\Extension\AiGovernance\Exception\AiGovernanceException when the
     *         acting person holds no assignment over this system, or holds one that
     *         does not confer the capacity the action exercises
     */
    public function recordIntervention(OversightIntervention $intervention): void;

    /**
     * Every recorded exercise of oversight over this system, oldest first.
     *
     * @param non-empty-string $modelId
     *
     * @return list<OversightIntervention>
     */
    #[NoDiscard]
    public function interventionsFor(string $modelId): array;
}
