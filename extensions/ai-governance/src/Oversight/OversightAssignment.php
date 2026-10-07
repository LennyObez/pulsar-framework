<?php

declare(strict_types=1);

namespace Pulsar\Extension\AiGovernance\Oversight;

use DateTimeImmutable;
use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Extension\AiGovernance\Exception\AiGovernanceException;

use function in_array;
use function trim;

/**
 * One natural person assigned to oversee one AI system.
 *
 * Article 26(2) is the sentence this type exists to make un-fakeable: deployers
 * "shall assign human oversight to natural persons who have the necessary
 * competence, training and authority, as well as the necessary support". Article
 * 14(1) is the duty on the other side of it — the system shall be designed so
 * that it CAN be effectively overseen — and the two only meet when a named person
 * holds capacities the system actually confers.
 *
 * FIVE REFUSALS, EACH FOR A LIMB OF THE ARTICLE THAT IS OTHERWISE UNCHECKABLE.
 *
 * The overseer is a natural person, identified. Article 26(2) says natural
 * persons; a record naming a team, a rota or a service account is a record that
 * no one in particular is responsible, which is the state the Article was written
 * against. This type cannot tell a person's identifier from a team's, so it
 * requires an identifier and the report shows it — but it does refuse an empty
 * one, because a nameless assignment is not an assignment.
 *
 * Competence is stated, not asserted. `competenceBasis` holds the sentence saying
 * what makes this person competent and trained for this system — the
 * qualification, the training completed, the domain experience. A boolean meaning
 * "yes they're competent" would be a claim with nothing behind it; there is no
 * way to satisfy this type without having written down the basis.
 *
 * Authority is stated separately, because Article 26(2) lists it separately and
 * they are different things. A clinician may be entirely competent to judge a
 * triage recommendation and hold no authority to stop the system. `authorityBasis`
 * names the mandate — the role, the delegation, the policy — under which this
 * person may act.
 *
 * The assignment confers at least one capacity a person can ACT with. Article
 * 14(4)(d) and (e) are the two points that name an action against the running
 * system — disregard or reverse the output, intervene or interrupt the operation
 * — and an assignment conferring neither describes someone who watches. It does
 * NOT require one person to hold both, because Article 14(4) asks that the
 * oversight measures enable those actions, not that every individual can take
 * every one of them: a reviewer who may disregard a recommendation and a
 * supervisor who may halt the service are a normal and adequate arrangement. What
 * must hold across the whole arrangement is checked where the arrangement lives,
 * by {@see \Pulsar\Extension\AiGovernance\Contracts\HumanOversightInterface::isOverseen()}.
 *
 * The other three points of Article 14(4) are properties of the person's training
 * and are recorded here when they apply, never required, because a framework
 * scoring a human being's understanding from a row is not doing compliance.
 *
 * NO CLOCK IS READ. `assignedAt` is handed in by whatever made the assignment,
 * for the same reason the transparency subsystem refuses to invent a generation
 * time: a record stamped with the moment it was written down says when it was
 * written down, not when the person was assigned.
 * @api
 */
#[Api(since: '1.0.0-rc.12')]
final readonly class OversightAssignment
{
    /**
     * @param non-empty-string $modelId the registered system this person oversees
     * @param non-empty-string $overseerId the natural person, as this deployment identifies people
     * @param non-empty-string $competenceBasis what makes this person competent and trained
     *        for this system (Article 26(2))
     * @param non-empty-string $authorityBasis the mandate under which this person may act
     *        against the running system (Article 26(2))
     * @param list<OversightCapability> $capabilities the Article 14(4) capacities this
     *        assignment confers; must include at least one the person can act with
     */
    public function __construct(
        public string $modelId,
        public string $overseerId,
        public string $competenceBasis,
        public string $authorityBasis,
        public array $capabilities,
        public DateTimeImmutable $assignedAt,
    ) {
        // Identity first, so every refusal below can name the system and the
        // person it is about.
        if (trim($this->modelId) === '') {
            throw AiGovernanceException::oversightModelRequired();
        }

        if (trim($this->overseerId) === '') {
            throw AiGovernanceException::oversightOverseerRequired($this->modelId);
        }

        if (trim($this->competenceBasis) === '') {
            throw AiGovernanceException::oversightCompetenceRequired($this->modelId, $this->overseerId);
        }

        if (trim($this->authorityBasis) === '') {
            throw AiGovernanceException::oversightAuthorityRequired($this->modelId, $this->overseerId);
        }

        if (! $this->confersAnyAction()) {
            throw AiGovernanceException::oversightConfersNoAction(
                $this->modelId,
                $this->overseerId,
            );
        }
    }

    /**
     * Whether this assignment confers a given Article 14(4) capacity.
     */
    #[NoDiscard]
    public function confers(OversightCapability $capability): bool
    {
        return in_array($capability, $this->capabilities, true);
    }

    /**
     * Whether this person can do anything to the system at all.
     *
     * True when the assignment confers at least one of the two Article 14(4)
     * points that name an action. An assignment conferring only the three
     * understanding-side points gives a person nothing to do when they see the
     * system go wrong.
     */
    #[NoDiscard]
    public function confersAnyAction(): bool
    {
        foreach ($this->capabilities as $capability) {
            if ($capability->mustBeExercisable()) {
                return true;
            }
        }

        return false;
    }
}
