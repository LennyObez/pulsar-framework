<?php

declare(strict_types=1);

namespace Pulsar\Extension\AiGovernance\Oversight;

use DateTimeImmutable;
use Pulsar\Api\Api;
use Pulsar\Extension\AiGovernance\Exception\AiGovernanceException;

use function trim;

/**
 * One occasion on which a person overrode the machine.
 *
 * THIS IS WHAT SEPARATES OVERSIGHT FROM A POLICY DOCUMENT. An
 * {@see OversightAssignment} records that a competent person holds the authority
 * to stop the system; it does not record that anyone ever could. Article 14
 * requires the system to be designed so oversight is EFFECTIVE, and a deployment
 * that has never once seen an overseer disregard an output has an arrangement
 * nobody has tested. An intervention register is the only artefact in this
 * subsystem that is evidence rather than intent.
 *
 * THE RATIONALE IS REQUIRED, and it is the field that costs something to write.
 * An override with no stated reason is indistinguishable from a mis-click, and
 * where the override reversed a decision that affected a person, that person's
 * right to contest it under GDPR Article 22(3) is exercised against this sentence.
 * A register of overrides with no reasons is a count, not a record.
 *
 * THE DECISION ID IS OPTIONAL AND IT IS NOT DECORATION. Where the override
 * concerned one decision the system made, naming it links this record to the
 * {@see \Pulsar\Extension\AiGovernance\Dto\Explanation} recorded for that
 * decision, so a contested decision and the human intervention over it are one
 * story instead of two registers nobody joins. It is null for
 * {@see OversightAction::InterruptedOperation}, which halts the system rather
 * than answering any single decision — and null there is the honest value, not a
 * missing one.
 *
 * NO CLOCK IS READ, for the third time in this extension and the same reason:
 * `occurredAt` is when the person acted, which whatever observed the action
 * knows and this record does not.
 * @api
 */
#[Api(since: '1.0.0-rc.12')]
final readonly class OversightIntervention
{
    /**
     * @param non-empty-string $interventionId unique identifier for this intervention
     * @param non-empty-string $modelId the system that was overridden
     * @param non-empty-string $overseerId the natural person who acted
     * @param non-empty-string $rationale why they acted, in the words of whoever recorded it
     * @param non-empty-string|null $decisionId the decision this override concerned, where it
     *        concerned one; null for an interruption of the system as a whole
     */
    public function __construct(
        public string $interventionId,
        public string $modelId,
        public string $overseerId,
        public OversightAction $action,
        public string $rationale,
        public DateTimeImmutable $occurredAt,
        public ?string $decisionId = null,
    ) {
        if (trim($this->interventionId) === '') {
            throw AiGovernanceException::interventionIdRequired();
        }

        if (trim($this->modelId) === '') {
            throw AiGovernanceException::oversightModelRequired();
        }

        if (trim($this->overseerId) === '') {
            throw AiGovernanceException::oversightOverseerRequired($this->modelId);
        }

        if (trim($this->rationale) === '') {
            throw AiGovernanceException::interventionRationaleRequired(
                $this->interventionId,
                $this->modelId,
            );
        }

        // An empty string is refused rather than read as absence. Null means the
        // override concerned no single decision; '' would mean it concerned one
        // that nothing can name, which is a broken link dressed as an absent one.
        if ($this->decisionId !== null && trim($this->decisionId) === '') {
            throw AiGovernanceException::interventionDecisionBlank($this->interventionId);
        }
    }
}
