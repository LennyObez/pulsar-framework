<?php

declare(strict_types=1);

namespace Pulsar\Extension\AiGovernance\Internal\Store;

use DateTimeImmutable;
use DateTimeZone;
use NoDiscard;
use Override;
use Pulsar\Api\Internal;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Row;
use Pulsar\Extension\AiGovernance\Contracts\AiImpactAssessmentInterface;
use Pulsar\Extension\AiGovernance\Dto\ImpactFinding;
use Pulsar\Extension\AiGovernance\Enum\ImpactCategory;
use Pulsar\Extension\AiGovernance\Enum\ImpactSeverity;

use function array_map;
use function count;
use function min;
use function round;

/**
 * Impact assessments and their findings, held where they survive a restart.
 *
 * ISO 42001:2023 Clause 6.1.2 and Clause 8.2 both ask for an AI system impact
 * assessment, and the EU AI Act Article 9 makes one a precondition of placing a
 * high-risk system on the market. {@see InMemoryImpactAssessmentStore} holds the
 * answer in a PHP array, which means the deployment gate that reads
 * `hasAssessment()` is asking whether an assessment was recorded *since the last
 * restart* — so a model that passed its gate in the morning is refused in the
 * afternoon for the same reason, and one that was never assessed passes if a
 * finding happened to be added in the same request.
 *
 * WHAT `assess()` DOES AND DOES NOT DO, stated because the in-memory store's
 * version says it in a comment and then does nothing. Neither store performs an
 * automated assessment, and neither can: the categories in {@see ImpactCategory}
 * are judgements about a system's effect on people, and a framework that scored
 * them from a model's metadata would be manufacturing the finding an assessor is
 * supposed to be shown. What this method does is OPEN an assessment: it records
 * that the model was assessed, when, and against which categories, so that
 * "assessed and nothing adverse was found" is a record rather than the absence of
 * one. Findings are added through {@see addFinding()} by whoever made them.
 *
 * FINDINGS ARE KEYED BY THEIR OWN ID, which {@see ImpactFinding} documents as a
 * "unique finding identifier". Re-adding the same finding corrects it rather than
 * duplicating it — the in-memory store appends blindly and would inflate the risk
 * score of any model whose assessment was replayed.
 */
#[Internal(reason: 'Durable impact assessment store; use AiImpactAssessmentInterface for access')]
final readonly class DbImpactAssessmentStore implements AiImpactAssessmentInterface
{
    private const string ASSESSMENTS = 'ai_impact_assessments';

    private const string FINDINGS = 'ai_impact_findings';

    public function __construct(
        private ConnectionInterface $connection,
    ) {}

    #[Override]
    public function assess(string $modelId, array $categories = []): void
    {
        $insert = 'INSERT INTO ' . self::ASSESSMENTS . ' (model_id, categories, assessed_at)'
            . ' VALUES (:model_id, :categories, :assessed_at)';

        $this->connection->execute(
            $this->connection->dialect()->compileUpsert($insert, ['model_id'], ['categories', 'assessed_at']),
            [
                'model_id' => $modelId,
                'categories' => StoredValue::encode(array_map(
                    static fn(ImpactCategory $category): string => $category->value,
                    $categories === [] ? ImpactCategory::cases() : $categories,
                )),
                'assessed_at' => StoredValue::instantToStore($this->now()),
            ],
        );
    }

    #[Override]
    public function hasAssessment(string $modelId): bool
    {
        return $this->connection->query(
            'SELECT model_id FROM ' . self::ASSESSMENTS . ' WHERE model_id = :model_id',
            ['model_id' => $modelId],
        )->first() !== null;
    }

    /**
     * Record a finding against a model, opening the assessment if none is open.
     *
     * The in-memory store has the same method and the same reason for it: neither
     * store can generate a finding, so the only way one enters the record is a
     * caller putting it there. Opening the assessment as a side effect matches
     * that store's behaviour — it creates the model's finding list on first add —
     * and it keeps the invariant `getFindings()` non-empty implies
     * `hasAssessment()` true, which the impact deployment gate relies on.
     *
     * @param non-empty-string $modelId The model the finding is against. Not on the
     *        interface, so it is stated here: the id is forwarded to `hasAssessment()`
     *        and `assess()`, both of which require one, and an empty id would open an
     *        assessment against no model
     */
    public function addFinding(string $modelId, ImpactFinding $finding): void
    {
        if (! $this->hasAssessment($modelId)) {
            $this->assess($modelId);
        }

        $insert = 'INSERT INTO ' . self::FINDINGS
            . ' (model_id, finding_id, category, severity, title, description, recommendation, recorded_at)'
            . ' VALUES (:model_id, :finding_id, :category, :severity, :title, :description,'
            . ' :recommendation, :recorded_at)';

        $this->connection->execute(
            $this->connection->dialect()->compileUpsert(
                $insert,
                ['model_id', 'finding_id'],
                ['category', 'severity', 'title', 'description', 'recommendation', 'recorded_at'],
            ),
            [
                'model_id' => $modelId,
                'finding_id' => $finding->id,
                'category' => $finding->category->value,
                'severity' => $finding->severity->value,
                'title' => $finding->title,
                'description' => $finding->description,
                'recommendation' => $finding->recommendation,
                'recorded_at' => StoredValue::instantToStore($this->now()),
            ],
        );
    }

    #[Override]
    public function getFindings(string $modelId): array
    {
        return $this->connection->query(
            'SELECT * FROM ' . self::FINDINGS . ' WHERE model_id = :model_id'
                . ' ORDER BY recorded_at ASC, finding_id ASC',
            ['model_id' => $modelId],
        )->map(fn(Row $row): ImpactFinding => $this->hydrate($row));
    }

    #[Override]
    public function getRiskScore(string $modelId): float
    {
        $findings = $this->getFindings($modelId);

        if (count($findings) === 0) {
            return 0.0;
        }

        $total = 0.0;

        foreach ($findings as $finding) {
            $total += self::weight($finding->severity);
        }

        return min(10.0, round($total / count($findings), 2));
    }

    /**
     * The severity weights, identical to {@see InMemoryImpactAssessmentStore}.
     *
     * Duplicated rather than shared, because the two stores are alternative
     * implementations of a published contract and a deployment that swapped one
     * for the other must not see its risk scores move. A shared helper would make
     * that guarantee depend on the development store shipping forever.
     */
    #[NoDiscard]
    private static function weight(ImpactSeverity $severity): float
    {
        return match ($severity) {
            ImpactSeverity::Low => 1.0,
            ImpactSeverity::Medium => 3.0,
            ImpactSeverity::High => 6.0,
            ImpactSeverity::Critical => 10.0,
        };
    }

    private function hydrate(Row $row): ImpactFinding
    {
        return new ImpactFinding(
            id: StoredValue::required($row->getString('finding_id'), self::FINDINGS, 'finding_id'),
            category: ImpactCategory::from($row->getString('category')),
            severity: ImpactSeverity::from($row->getString('severity')),
            title: StoredValue::required($row->getString('title'), self::FINDINGS, 'title'),
            description: StoredValue::required($row->getString('description'), self::FINDINGS, 'description'),
            recommendation: StoredValue::required(
                $row->getString('recommendation'),
                self::FINDINGS,
                'recommendation',
            ),
        );
    }

    /**
     * The instant an assessment or a finding is recorded at.
     *
     * Read from the clock here, and this is the one place in the extension where
     * that is correct: the store is what performs the write, so it is what knows
     * when the write happened. Contrast {@see \Pulsar\Extension\AiGovernance\Dto\MonitoringRecord},
     * whose instant is handed in because the check ran somewhere else.
     */
    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}
