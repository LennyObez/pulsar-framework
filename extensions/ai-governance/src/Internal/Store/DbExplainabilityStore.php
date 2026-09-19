<?php

declare(strict_types=1);

namespace Pulsar\Extension\AiGovernance\Internal\Store;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Row;
use Pulsar\Extension\AiGovernance\Contracts\ExplainabilityInterface;
use Pulsar\Extension\AiGovernance\Dto\DecisionFactor;
use Pulsar\Extension\AiGovernance\Dto\Explanation;
use Pulsar\Extension\AiGovernance\Exception\AiGovernanceException;

use function is_array;
use function is_float;
use function is_int;
use function is_string;

/**
 * Recorded explanations, held where the person a decision affected can still be
 * given one.
 *
 * ISO 42001:2023 Clause 8.4 and Annex A.8.5 ask for transparency about how an AI
 * system reaches a decision, and GDPR Article 22(3) gives the data subject of an
 * automated decision the right to contest it — which is a right exercised days or
 * weeks after the decision, by a person who was not there when it was made.
 * {@see InMemoryExplainabilityStore} holds the explanation in the memory of the
 * worker that produced it, so it is gone before anyone asks. That is the gap this
 * store closes, and it is the whole substance of the control.
 *
 * KEYED BY DECISION ID, matching the in-memory store: `explain()` takes a
 * decision and returns the explanation for it, so re-recording the same decision
 * replaces its explanation rather than accumulating versions the contract has no
 * way to distinguish.
 */
#[Internal(reason: 'Durable explainability store; use ExplainabilityInterface for access')]
final readonly class DbExplainabilityStore implements ExplainabilityInterface
{
    private const string TABLE = 'ai_explanations';

    public function __construct(
        private ConnectionInterface $connection,
    ) {}

    #[Override]
    public function explain(string $decisionId): ?Explanation
    {
        $row = $this->connection->query(
            'SELECT * FROM ' . self::TABLE . ' WHERE decision_id = :decision_id',
            ['decision_id' => $decisionId],
        )->first();

        return $row === null ? null : $this->hydrate($row);
    }

    #[Override]
    public function record(Explanation $explanation): void
    {
        $insert = 'INSERT INTO ' . self::TABLE
            . ' (decision_id, model_id, summary, factors, confidence, alternatives_considered, generated_at)'
            . ' VALUES (:decision_id, :model_id, :summary, :factors, :confidence,'
            . ' :alternatives_considered, :generated_at)';

        $this->connection->execute(
            $this->connection->dialect()->compileUpsert(
                $insert,
                ['decision_id'],
                ['model_id', 'summary', 'factors', 'confidence', 'alternatives_considered', 'generated_at'],
            ),
            [
                'decision_id' => $explanation->decisionId,
                'model_id' => $explanation->modelId,
                'summary' => $explanation->summary,
                'factors' => StoredValue::encode(self::factorsToArray($explanation->factors)),
                'confidence' => $explanation->confidence,
                'alternatives_considered' => StoredValue::encode($explanation->alternativesConsidered),
                'generated_at' => StoredValue::instantToStore($explanation->generatedAt),
            ],
        );
    }

    #[Override]
    public function getByModel(string $modelId): array
    {
        return $this->connection->query(
            'SELECT * FROM ' . self::TABLE . ' WHERE model_id = :model_id'
                . ' ORDER BY generated_at ASC, decision_id ASC',
            ['model_id' => $modelId],
        )->map(fn(Row $row): Explanation => $this->hydrate($row));
    }

    private function hydrate(Row $row): Explanation
    {
        return new Explanation(
            decisionId: StoredValue::required($row->getString('decision_id'), self::TABLE, 'decision_id'),
            modelId: StoredValue::required($row->getString('model_id'), self::TABLE, 'model_id'),
            summary: StoredValue::required($row->getString('summary'), self::TABLE, 'summary'),
            factors: $this->hydrateFactors($row->getString('factors')),
            confidence: $row->getFloat('confidence'),
            generatedAt: StoredValue::instant($row->getString('generated_at'), self::TABLE, 'generated_at'),
            alternativesConsidered: StoredValue::stringList(
                $row->getString('alternatives_considered'),
                self::TABLE,
                'alternatives_considered',
            ),
        );
    }

    /**
     * @return list<DecisionFactor>
     */
    private function hydrateFactors(string $stored): array
    {
        $factors = [];

        /** @var mixed $entry */
        foreach (StoredValue::decodeArray($stored, self::TABLE, 'factors') as $entry) {
            if (! is_array($entry)) {
                throw AiGovernanceException::corruptGovernanceRecord(self::TABLE, 'factors');
            }

            /** @var mixed $name */
            $name = $entry['name'] ?? null;
            /** @var mixed $weight */
            $weight = $entry['weight'] ?? null;
            /** @var mixed $description */
            $description = $entry['description'] ?? null;

            if (! is_string($name) || ! is_string($description) || ! (is_float($weight) || is_int($weight))) {
                throw AiGovernanceException::corruptGovernanceRecord(self::TABLE, 'factors');
            }

            $factors[] = new DecisionFactor(
                name: StoredValue::required($name, self::TABLE, 'factors.name'),
                weight: (float) $weight,
                description: StoredValue::required($description, self::TABLE, 'factors.description'),
            );
        }

        return $factors;
    }

    /**
     * @param list<DecisionFactor> $factors
     *
     * @return list<array{name: string, weight: float, description: string}>
     */
    private static function factorsToArray(array $factors): array
    {
        $encoded = [];

        foreach ($factors as $factor) {
            $encoded[] = [
                'name' => $factor->name,
                'weight' => $factor->weight,
                'description' => $factor->description,
            ];
        }

        return $encoded;
    }
}
