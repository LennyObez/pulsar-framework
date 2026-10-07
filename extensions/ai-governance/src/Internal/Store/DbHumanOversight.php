<?php

declare(strict_types=1);

namespace Pulsar\Extension\AiGovernance\Internal\Store;

use NoDiscard;
use Override;
use Pulsar\Api\Internal;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Row;
use Pulsar\Extension\AiGovernance\Contracts\HumanOversightInterface;
use Pulsar\Extension\AiGovernance\Exception\AiGovernanceException;
use Pulsar\Extension\AiGovernance\Oversight\OversightAction;
use Pulsar\Extension\AiGovernance\Oversight\OversightAssignment;
use Pulsar\Extension\AiGovernance\Oversight\OversightCapability;
use Pulsar\Extension\AiGovernance\Oversight\OversightIntervention;

use function is_string;

/**
 * The Article 14 oversight record, held where it outlives the process.
 *
 * WHY THIS IS THE DEFAULT AND NOT THE OPTION. Article 26(2) is a continuing duty
 * — oversight is assigned for the period in which the system is in use — and
 * Article 26(6) makes the deployer keep the automatically generated logs for at
 * least six months. A record of who oversees a system that is rebuilt from
 * nothing at every restart cannot answer either. The intervention register is
 * worse still if it evaporates: it is the only artefact in this subsystem that
 * shows the oversight arrangement has ever been exercised, and it is what a
 * person contesting a reversed decision under GDPR Article 22(3) is shown weeks
 * after the fact.
 *
 * TWO TABLES BECAUSE THEY ARE TWO KINDS OF THING. An assignment is a CURRENT
 * state, keyed by system and person, replaced when re-assigned and removed when
 * withdrawn. An intervention is an EVENT, keyed by its own identifier, and
 * nothing removes it — {@see withdraw()} deliberately leaves the interventions
 * behind, because what a person did while they held the authority happened, and a
 * register that emptied when a mandate was revoked would be evidence of nothing.
 *
 * THE INVARIANTS ARE HELD HERE, not inherited. An intervention is refused for a
 * person holding no assignment over the system, and refused when the assignment
 * does not confer the capacity the action exercises. {@see InMemoryHumanOversight}
 * holds the same two checks in its own body; the contract documents them as
 * requirements of any implementation, and a durable store that borrowed them from
 * a development store would be relying on that store shipping forever.
 *
 * @internal
 */
#[Internal(reason: 'Durable human oversight store; use HumanOversightInterface for access')]
final readonly class DbHumanOversight implements HumanOversightInterface
{
    private const string ASSIGNMENTS = 'ai_oversight_assignments';

    private const string INTERVENTIONS = 'ai_oversight_interventions';

    public function __construct(
        private ConnectionInterface $connection,
    ) {}

    #[Override]
    public function assign(OversightAssignment $assignment): void
    {
        $insert = 'INSERT INTO ' . self::ASSIGNMENTS
            . ' (model_id, overseer_id, competence_basis, authority_basis, capabilities, assigned_at)'
            . ' VALUES (:model_id, :overseer_id, :competence_basis, :authority_basis, :capabilities,'
            . ' :assigned_at)';

        $this->connection->execute(
            $this->connection->dialect()->compileUpsert(
                $insert,
                ['model_id', 'overseer_id'],
                ['competence_basis', 'authority_basis', 'capabilities', 'assigned_at'],
            ),
            [
                'model_id' => $assignment->modelId,
                'overseer_id' => $assignment->overseerId,
                'competence_basis' => $assignment->competenceBasis,
                'authority_basis' => $assignment->authorityBasis,
                'capabilities' => StoredValue::encode(self::capabilitiesToArray($assignment->capabilities)),
                'assigned_at' => StoredValue::instantToStore($assignment->assignedAt),
            ],
        );
    }

    #[Override]
    public function withdraw(string $modelId, string $overseerId): void
    {
        // Only the assignment. The interventions this person recorded stay where
        // they are; see the class docblock.
        $this->connection->execute(
            'DELETE FROM ' . self::ASSIGNMENTS
                . ' WHERE model_id = :model_id AND overseer_id = :overseer_id',
            ['model_id' => $modelId, 'overseer_id' => $overseerId],
        );
    }

    /**
     * @return list<OversightAssignment>
     */
    #[Override]
    #[NoDiscard]
    public function assignmentsFor(string $modelId): array
    {
        return $this->connection->query(
            'SELECT * FROM ' . self::ASSIGNMENTS . ' WHERE model_id = :model_id'
                . ' ORDER BY assigned_at ASC, overseer_id ASC',
            ['model_id' => $modelId],
        )->map(fn(Row $row): OversightAssignment => $this->hydrateAssignment($row));
    }

    #[Override]
    #[NoDiscard]
    public function isOverseen(string $modelId): bool
    {
        // Only the capabilities column is read, not the whole assignment. The
        // question is whether Article 26(2) has been discharged for this system,
        // and an unrelated corrupt column — a competence basis someone blanked —
        // must not be able to report a system as unoverseen while a valid
        // arrangement sits beside it.
        $conferred = [];

        foreach ($this->connection->query(
            'SELECT capabilities FROM ' . self::ASSIGNMENTS . ' WHERE model_id = :model_id',
            ['model_id' => $modelId],
        )->rows as $row) {
            foreach ($this->hydrateCapabilities($row->getString('capabilities')) as $capability) {
                $conferred[] = $capability;
            }
        }

        // Over the arrangement as a whole: Article 14(4) asks that the oversight
        // measures enable both actions, not that one person can take both.
        foreach (OversightCapability::exercisable() as $required) {
            if (! self::confers($conferred, $required)) {
                return false;
            }
        }

        return true;
    }

    #[Override]
    public function recordIntervention(OversightIntervention $intervention): void
    {
        $row = $this->connection->query(
            'SELECT capabilities FROM ' . self::ASSIGNMENTS
                . ' WHERE model_id = :model_id AND overseer_id = :overseer_id',
            ['model_id' => $intervention->modelId, 'overseer_id' => $intervention->overseerId],
        )->first();

        if ($row === null) {
            throw AiGovernanceException::interventionWithoutAssignment(
                $intervention->modelId,
                $intervention->overseerId,
            );
        }

        $capability = $intervention->action->exercises();
        $conferred = $this->hydrateCapabilities($row->getString('capabilities'));

        if (! self::confers($conferred, $capability)) {
            throw AiGovernanceException::interventionCapabilityNotConferred(
                $intervention->modelId,
                $intervention->overseerId,
                $capability->value,
            );
        }

        $insert = 'INSERT INTO ' . self::INTERVENTIONS
            . ' (intervention_id, model_id, overseer_id, action, rationale, decision_id, occurred_at)'
            . ' VALUES (:intervention_id, :model_id, :overseer_id, :action, :rationale, :decision_id,'
            . ' :occurred_at)';

        $this->connection->execute(
            $this->connection->dialect()->compileUpsert(
                $insert,
                ['intervention_id'],
                ['model_id', 'overseer_id', 'action', 'rationale', 'decision_id', 'occurred_at'],
            ),
            [
                'intervention_id' => $intervention->interventionId,
                'model_id' => $intervention->modelId,
                'overseer_id' => $intervention->overseerId,
                'action' => $intervention->action->value,
                'rationale' => $intervention->rationale,
                'decision_id' => $intervention->decisionId,
                'occurred_at' => StoredValue::instantToStore($intervention->occurredAt),
            ],
        );
    }

    /**
     * @return list<OversightIntervention>
     */
    #[Override]
    #[NoDiscard]
    public function interventionsFor(string $modelId): array
    {
        return $this->connection->query(
            'SELECT * FROM ' . self::INTERVENTIONS . ' WHERE model_id = :model_id'
                . ' ORDER BY occurred_at ASC, intervention_id ASC',
            ['model_id' => $modelId],
        )->map(fn(Row $row): OversightIntervention => $this->hydrateIntervention($row));
    }

    private function hydrateAssignment(Row $row): OversightAssignment
    {
        return new OversightAssignment(
            modelId: StoredValue::required($row->getString('model_id'), self::ASSIGNMENTS, 'model_id'),
            overseerId: StoredValue::required(
                $row->getString('overseer_id'),
                self::ASSIGNMENTS,
                'overseer_id',
            ),
            competenceBasis: StoredValue::required(
                $row->getString('competence_basis'),
                self::ASSIGNMENTS,
                'competence_basis',
            ),
            authorityBasis: StoredValue::required(
                $row->getString('authority_basis'),
                self::ASSIGNMENTS,
                'authority_basis',
            ),
            capabilities: $this->hydrateCapabilities($row->getString('capabilities')),
            assignedAt: StoredValue::instant(
                $row->getString('assigned_at'),
                self::ASSIGNMENTS,
                'assigned_at',
            ),
        );
    }

    private function hydrateIntervention(Row $row): OversightIntervention
    {
        $action = OversightAction::tryFrom($row->getString('action'));

        if (! $action instanceof OversightAction) {
            throw AiGovernanceException::corruptGovernanceRecord(self::INTERVENTIONS, 'action');
        }

        return new OversightIntervention(
            interventionId: StoredValue::required(
                $row->getString('intervention_id'),
                self::INTERVENTIONS,
                'intervention_id',
            ),
            modelId: StoredValue::required($row->getString('model_id'), self::INTERVENTIONS, 'model_id'),
            overseerId: StoredValue::required(
                $row->getString('overseer_id'),
                self::INTERVENTIONS,
                'overseer_id',
            ),
            action: $action,
            rationale: StoredValue::required(
                $row->getString('rationale'),
                self::INTERVENTIONS,
                'rationale',
            ),
            occurredAt: StoredValue::instant(
                $row->getString('occurred_at'),
                self::INTERVENTIONS,
                'occurred_at',
            ),
            decisionId: StoredValue::optional($row->getNullableString('decision_id')),
        );
    }

    /**
     * @return list<OversightCapability>
     */
    private function hydrateCapabilities(string $stored): array
    {
        $capabilities = [];

        /** @var mixed $entry */
        foreach (StoredValue::decodeArray($stored, self::ASSIGNMENTS, 'capabilities') as $entry) {
            if (! is_string($entry)) {
                throw AiGovernanceException::corruptGovernanceRecord(self::ASSIGNMENTS, 'capabilities');
            }

            // tryFrom rather than from, so a capacity outside Article 14(4)'s own
            // five points surfaces as a corrupt governance record naming the
            // column instead of as a ValueError naming an enum.
            $capability = OversightCapability::tryFrom($entry);

            if (! $capability instanceof OversightCapability) {
                throw AiGovernanceException::corruptGovernanceRecord(self::ASSIGNMENTS, 'capabilities');
            }

            $capabilities[] = $capability;
        }

        return $capabilities;
    }

    /**
     * @param list<OversightCapability> $conferred
     */
    private static function confers(array $conferred, OversightCapability $capability): bool
    {
        foreach ($conferred as $candidate) {
            if ($candidate === $capability) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<OversightCapability> $capabilities
     *
     * @return list<string>
     */
    private static function capabilitiesToArray(array $capabilities): array
    {
        $encoded = [];

        foreach ($capabilities as $capability) {
            $encoded[] = $capability->value;
        }

        return $encoded;
    }
}
