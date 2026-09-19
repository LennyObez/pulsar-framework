<?php

declare(strict_types=1);

namespace Pulsar\Extension\AiGovernance\Internal\Store;

use NoDiscard;
use Override;
use Pulsar\Api\Internal;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Row;
use Pulsar\Extension\AiGovernance\Contracts\AiModelRegistryInterface;
use Pulsar\Extension\AiGovernance\Dto\AiModel;
use Pulsar\Extension\AiGovernance\Dto\ModelCard;
use Pulsar\Extension\AiGovernance\Enum\AiActorRole;
use Pulsar\Extension\AiGovernance\Enum\AiModelRiskLevel;
use Pulsar\Extension\AiGovernance\Enum\AiModelStatus;
use Pulsar\Extension\AiGovernance\Exception\AiGovernanceException;

use function array_combine;
use function array_keys;
use function array_values;
use function in_array;
use function is_array;
use function is_string;

/**
 * The AI system inventory, held where it survives the process that wrote it.
 *
 * ISO 42001:2023 Clause 8.2 asks an organisation to identify and document the AI
 * systems in its scope, and Clause 7.5 asks for that documentation to be
 * retained. {@see InMemoryModelRegistry} answers the first and not the second: a
 * model registered by one worker is unknown to the next, so the inventory an
 * assessor is shown is whatever happened to be registered since the last restart.
 * That is the store this class replaces as the default.
 *
 * EVERY INVARIANT THE IN-MEMORY REGISTRY HOLDS IS HELD HERE, and held in the same
 * place rather than delegated: the Article 5 refusals in {@see register()} and
 * {@see transitionStatus()}, the withdrawal in {@see updateRiskLevel()}, and the
 * status state machine. They are duplicated deliberately. The contract documents
 * them as requirements of ANY implementation, and a durable registry that
 * inherited them from a development store would be relying on the development
 * store shipping forever.
 *
 * WRITES ARE UPSERTS BY MODEL ID, which is what `AiModel->id` is documented to be
 * — "unique model identifier". Re-registering an id replaces the record rather
 * than raising, matching {@see InMemoryModelRegistry} exactly; a registry that
 * refused the second registration would make correcting a model's metadata
 * impossible without a deletion path this contract does not have.
 */
#[Internal(reason: 'Durable model registry; use AiModelRegistryInterface for access')]
final readonly class DbModelRegistry implements AiModelRegistryInterface
{
    private const string TABLE = 'ai_models';

    /**
     * The transitions this registry allows, mirroring {@see InMemoryModelRegistry}.
     *
     * @var array<string, list<string>>
     */
    private const array VALID_TRANSITIONS = [
        'development' => ['testing', 'retired'],
        'testing' => ['staging', 'development', 'retired'],
        'staging' => ['production', 'testing', 'retired'],
        'production' => ['deprecated', 'staging'],
        'deprecated' => ['retired', 'production'],
        'retired' => [],
    ];

    public function __construct(
        private ConnectionInterface $connection,
    ) {}

    #[Override]
    public function register(AiModel $model): void
    {
        if ($model->status === AiModelStatus::Production && $model->riskLevel->isProhibited()) {
            throw AiGovernanceException::prohibitedPractice($model->id);
        }

        $this->write($model, previousStatus: $this->previousStatusOf($model->id));
    }

    #[Override]
    public function get(string $modelId): ?AiModel
    {
        $row = $this->connection->query(
            'SELECT * FROM ' . self::TABLE . ' WHERE id = :id',
            ['id' => $modelId],
        )->first();

        return $row === null ? null : $this->hydrate($row);
    }

    #[Override]
    public function transitionStatus(string $modelId, AiModelStatus $newStatus): AiModel
    {
        $model = $this->get($modelId);

        if ($model === null) {
            throw AiGovernanceException::modelNotFound($modelId);
        }

        // Checked before the transition table for the reason the in-memory
        // registry checks it there: a prohibited model is refused for the
        // regulation that governs it rather than for the shape of the state
        // machine it happened to violate on the way.
        if ($newStatus === AiModelStatus::Production && $model->riskLevel->isProhibited()) {
            throw AiGovernanceException::prohibitedPractice($modelId);
        }

        $allowed = self::VALID_TRANSITIONS[$model->status->value] ?? [];

        // Widened to a plain string for the membership check, exactly as
        // InMemoryModelRegistry does it. A backed enum's ->value narrows to a union
        // of literals, and an analyser then evaluates the transition table one
        // literal at a time and reports the arms that cannot match as dead code --
        // which would make the state machine's own guard look like a mistake.
        /** @var string $candidate */
        $candidate = $newStatus->value;

        if (! in_array($candidate, $allowed, true)) {
            throw AiGovernanceException::invalidStatusTransition(
                $modelId,
                $model->status->value,
                $newStatus->value,
            );
        }

        $updated = $model->withStatus($newStatus);

        $this->write($updated, previousStatus: $model->status);

        return $updated;
    }

    /**
     * Repeats the interface's `#[NoDiscard]`, which PHP does not inherit: this
     * method can withdraw a live model from production, and a caller that drops
     * the return value is the caller that never finds out.
     */
    #[Override]
    #[NoDiscard]
    public function updateRiskLevel(string $modelId, AiModelRiskLevel $riskLevel): AiModel
    {
        $model = $this->get($modelId);

        if ($model === null) {
            throw AiGovernanceException::modelNotFound($modelId);
        }

        $updated = $model->withRiskLevel($riskLevel);
        $previousStatus = $this->previousStatusOf($modelId);

        // The one case where recording the truth and holding the invariant pull
        // against each other, resolved the way the in-memory registry resolves it:
        // refusing the update would leave a prohibited system in production AND
        // unrecorded. Deprecated rather than Retired, because a classification can
        // be corrected and Retired is terminal.
        if ($riskLevel->isProhibited() && $model->status === AiModelStatus::Production) {
            $previousStatus = $model->status;
            $updated = $updated->withStatus(AiModelStatus::Deprecated);
        }

        $this->write($updated, previousStatus: $previousStatus);

        return $updated;
    }

    #[Override]
    public function all(): array
    {
        $models = [];

        foreach ($this->connection->query('SELECT * FROM ' . self::TABLE)->map(
            fn(Row $row): AiModel => $this->hydrate($row),
        ) as $model) {
            $models[$model->id] = $model;
        }

        return $models;
    }

    #[Override]
    public function byStatus(AiModelStatus $status): array
    {
        return $this->connection->query(
            'SELECT * FROM ' . self::TABLE . ' WHERE status = :status',
            ['status' => $status->value],
        )->map(fn(Row $row): AiModel => $this->hydrate($row));
    }

    #[Override]
    public function byRiskLevel(AiModelRiskLevel $riskLevel): array
    {
        return $this->connection->query(
            'SELECT * FROM ' . self::TABLE . ' WHERE risk_level = :risk_level',
            ['risk_level' => $riskLevel->value],
        )->map(fn(Row $row): AiModel => $this->hydrate($row));
    }

    /**
     * The status a model held before its last transition, or null if it has never
     * moved.
     *
     * The durable counterpart of {@see InMemoryModelRegistry::getPreviousStatus()},
     * and durable for a reason that store cannot reach: the in-memory version
     * loses the answer on restart, so a rollback attempted by a worker other than
     * the one that deployed had nothing to roll back to.
     */
    #[NoDiscard]
    public function getPreviousStatus(string $modelId): ?AiModelStatus
    {
        return $this->previousStatusOf($modelId);
    }

    private function previousStatusOf(string $modelId): ?AiModelStatus
    {
        $row = $this->connection->query(
            'SELECT previous_status FROM ' . self::TABLE . ' WHERE id = :id',
            ['id' => $modelId],
        )->first();

        $stored = $row?->getNullableString('previous_status');

        return $stored === null ? null : AiModelStatus::from($stored);
    }

    private function write(AiModel $model, ?AiModelStatus $previousStatus): void
    {
        $insert = 'INSERT INTO ' . self::TABLE
            . ' (id, name, version, provider, type, risk_level, status, previous_status, card,'
            . ' actor_role, registered_at)'
            . ' VALUES (:id, :name, :version, :provider, :type, :risk_level, :status, :previous_status,'
            . ' :card, :actor_role, :registered_at)';

        $this->connection->execute(
            $this->connection->dialect()->compileUpsert(
                $insert,
                ['id'],
                [
                    'name',
                    'version',
                    'provider',
                    'type',
                    'risk_level',
                    'status',
                    'previous_status',
                    'card',
                    'actor_role',
                    'registered_at',
                ],
            ),
            [
                'id' => $model->id,
                'name' => $model->name,
                'version' => $model->version,
                'provider' => $model->provider,
                'type' => $model->type,
                'risk_level' => $model->riskLevel->value,
                'status' => $model->status->value,
                'previous_status' => $previousStatus?->value,
                'card' => $model->card === null ? null : StoredValue::encode(self::cardToArray($model->card)),
                // Null stays null. An undeclared role is not a role, and writing
                // a default here would turn 'nobody decided' into a decision the
                // deployment never made.
                'actor_role' => $model->actorRole?->value,
                'registered_at' => StoredValue::instantToStore($model->registeredAt),
            ],
        );
    }

    private function hydrate(Row $row): AiModel
    {
        return new AiModel(
            id: StoredValue::required($row->getString('id'), self::TABLE, 'id'),
            name: StoredValue::required($row->getString('name'), self::TABLE, 'name'),
            version: StoredValue::required($row->getString('version'), self::TABLE, 'version'),
            provider: StoredValue::required($row->getString('provider'), self::TABLE, 'provider'),
            type: StoredValue::required($row->getString('type'), self::TABLE, 'type'),
            riskLevel: AiModelRiskLevel::from($row->getString('risk_level')),
            status: AiModelStatus::from($row->getString('status')),
            registeredAt: StoredValue::instant(
                $row->getString('registered_at'),
                self::TABLE,
                'registered_at',
            ),
            card: $this->hydrateCard($row->getNullableString('card')),
            actorRole: $this->hydrateActorRole($row->getNullableString('actor_role')),
        );
    }

    /**
     * The declared EU AI Act role, or absence.
     *
     * A blank column reads as absence, matching {@see StoredValue::optional()}.
     * A column holding something that is not a role the Act defines is CORRUPT
     * and raises: reading it as absence would silently downgrade a stated role
     * to an unstated one, and the deployment would then be refused for a reason
     * it had already answered.
     */
    private function hydrateActorRole(?string $stored): ?AiActorRole
    {
        $value = StoredValue::optional($stored);

        if ($value === null) {
            return null;
        }

        $role = AiActorRole::tryFrom($value);

        if (! $role instanceof AiActorRole) {
            throw AiGovernanceException::corruptGovernanceRecord(self::TABLE, 'actor_role');
        }

        return $role;
    }

    private function hydrateCard(?string $stored): ?ModelCard
    {
        if ($stored === null || $stored === '') {
            return null;
        }

        $decoded = StoredValue::map($stored, self::TABLE, 'card');

        /** @var mixed $description */
        $description = $decoded['description'] ?? null;
        /** @var mixed $intendedUse */
        $intendedUse = $decoded['intended_use'] ?? null;

        if (! is_string($description) || ! is_string($intendedUse)) {
            throw AiGovernanceException::corruptGovernanceRecord(self::TABLE, 'card');
        }

        return new ModelCard(
            description: StoredValue::required($description, self::TABLE, 'card.description'),
            intendedUse: StoredValue::required($intendedUse, self::TABLE, 'card.intended_use'),
            capabilities: $this->cardStrings($decoded, 'capabilities'),
            limitations: $this->cardStrings($decoded, 'limitations'),
            knownBiases: $this->cardStrings($decoded, 'known_biases'),
            trainingDataSources: $this->cardStrings($decoded, 'training_data_sources'),
            performanceMetrics: $this->cardMap($decoded, 'performance_metrics'),
            ethicalConsiderations: $this->cardStrings($decoded, 'ethical_considerations'),
        );
    }

    /**
     * @param array<string, mixed> $card
     *
     * @return list<non-empty-string>
     */
    private function cardStrings(array $card, string $key): array
    {
        /** @var mixed $raw */
        $raw = $card[$key] ?? [];

        $list = [];

        if (! is_array($raw)) {
            throw AiGovernanceException::corruptGovernanceRecord(self::TABLE, 'card.' . $key);
        }

        /** @var mixed $item */
        foreach ($raw as $item) {
            if (! is_string($item) || $item === '') {
                throw AiGovernanceException::corruptGovernanceRecord(self::TABLE, 'card.' . $key);
            }

            $list[] = $item;
        }

        return $list;
    }

    /**
     * @param array<string, mixed> $card
     *
     * @return array<string, mixed>
     */
    private function cardMap(array $card, string $key): array
    {
        /** @var mixed $raw */
        $raw = $card[$key] ?? [];

        if (! is_array($raw)) {
            throw AiGovernanceException::corruptGovernanceRecord(self::TABLE, 'card.' . $key);
        }

        // Keys checked, then the map rebuilt from the checked keys and the
        // untouched values -- see StoredValue::map(), which does the same thing for
        // the same reason.
        $keys = [];

        foreach (array_keys($raw) as $mapKey) {
            if (! is_string($mapKey)) {
                throw AiGovernanceException::corruptGovernanceRecord(self::TABLE, 'card.' . $key);
            }

            $keys[] = $mapKey;
        }

        return array_combine($keys, array_values($raw));
    }

    /**
     * @return array<string, mixed>
     */
    private static function cardToArray(ModelCard $card): array
    {
        return [
            'description' => $card->description,
            'intended_use' => $card->intendedUse,
            'capabilities' => $card->capabilities,
            'limitations' => $card->limitations,
            'known_biases' => $card->knownBiases,
            'training_data_sources' => $card->trainingDataSources,
            'performance_metrics' => $card->performanceMetrics,
            'ethical_considerations' => $card->ethicalConsiderations,
        ];
    }
}
