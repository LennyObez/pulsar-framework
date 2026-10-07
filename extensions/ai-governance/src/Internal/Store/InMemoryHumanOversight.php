<?php

declare(strict_types=1);

namespace Pulsar\Extension\AiGovernance\Internal\Store;

use NoDiscard;
use Override;
use Pulsar\Api\Internal;
use Pulsar\Extension\AiGovernance\Contracts\HumanOversightInterface;
use Pulsar\Extension\AiGovernance\Exception\AiGovernanceException;
use Pulsar\Extension\AiGovernance\Oversight\OversightAssignment;
use Pulsar\Extension\AiGovernance\Oversight\OversightCapability;
use Pulsar\Extension\AiGovernance\Oversight\OversightIntervention;

use function array_values;
use function usort;

/**
 * The development store for oversight assignments and interventions.
 *
 * Usable, and evidence of nothing after a restart — the same statement the other
 * in-memory stores in this extension carry, and it lands harder here. An
 * intervention register is the only artefact in the Article 14 subsystem that is
 * evidence rather than intent, and an intervention record that disappears with
 * the worker cannot be produced for the assessor who asks whether the oversight
 * arrangement has ever been exercised, nor for the person contesting the decision
 * it reversed.
 *
 * {@see DbHumanOversight} is the default. This store exists for tests and for
 * local development, and a deployment that selects it is telling the compliance
 * report that its Article 26(2) record does not survive a restart.
 *
 * EVERY INVARIANT IS HELD IN BOTH STORES, in the same place rather than shared
 * through a base class: an intervention is refused for a person holding no
 * assignment, and refused for a person whose assignment does not confer the
 * capacity the action exercises. The durable store repeats them deliberately, so
 * that it does not depend on this one shipping forever.
 *
 * @internal
 */
#[Internal(reason: 'Development store behind HumanOversightInterface; the contract is the public surface')]
final class InMemoryHumanOversight implements HumanOversightInterface
{
    /** @var array<string, array<string, OversightAssignment>> */
    private array $assignments = [];

    /** @var array<string, list<OversightIntervention>> */
    private array $interventions = [];

    #[Override]
    public function assign(OversightAssignment $assignment): void
    {
        $this->assignments[$assignment->modelId][$assignment->overseerId] = $assignment;
    }

    #[Override]
    public function withdraw(string $modelId, string $overseerId): void
    {
        unset($this->assignments[$modelId][$overseerId]);
    }

    /**
     * @return list<OversightAssignment>
     */
    #[Override]
    #[NoDiscard]
    public function assignmentsFor(string $modelId): array
    {
        return array_values($this->assignments[$modelId] ?? []);
    }

    #[Override]
    #[NoDiscard]
    public function isOverseen(string $modelId): bool
    {
        // Computed from what is on record, never stored, and computed over the
        // arrangement as a whole: every capacity Article 14(4) requires to be
        // exercisable must be conferred on somebody, not necessarily on one
        // person. Counting assignments instead would report a room full of
        // reviewers who may disregard an output and nobody who may stop the
        // system as oversight.
        foreach (OversightCapability::exercisable() as $required) {
            $held = false;

            foreach ($this->assignments[$modelId] ?? [] as $assignment) {
                if ($assignment->confers($required)) {
                    $held = true;

                    break;
                }
            }

            if (! $held) {
                return false;
            }
        }

        return true;
    }

    #[Override]
    public function recordIntervention(OversightIntervention $intervention): void
    {
        $assignment = $this->assignments[$intervention->modelId][$intervention->overseerId] ?? null;

        if (! $assignment instanceof OversightAssignment) {
            throw AiGovernanceException::interventionWithoutAssignment(
                $intervention->modelId,
                $intervention->overseerId,
            );
        }

        $capability = $intervention->action->exercises();

        if (! $assignment->confers($capability)) {
            throw AiGovernanceException::interventionCapabilityNotConferred(
                $intervention->modelId,
                $intervention->overseerId,
                $capability->value,
            );
        }

        $this->interventions[$intervention->modelId][] = $intervention;
    }

    /**
     * @return list<OversightIntervention>
     */
    #[Override]
    #[NoDiscard]
    public function interventionsFor(string $modelId): array
    {
        $found = $this->interventions[$modelId] ?? [];

        // Oldest first, as the contract states, and by the instant the overseer
        // acted rather than by the order the records arrived: an intervention
        // written up late is still an intervention that happened when it happened.
        usort(
            $found,
            static fn(OversightIntervention $a, OversightIntervention $b): int
                => [$a->occurredAt, $a->interventionId] <=> [$b->occurredAt, $b->interventionId],
        );

        return $found;
    }
}
