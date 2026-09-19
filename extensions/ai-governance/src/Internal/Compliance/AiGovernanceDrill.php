<?php

declare(strict_types=1);

namespace Pulsar\Extension\AiGovernance\Internal\Compliance;

use DateTimeImmutable;
use DateTimeZone;
use NoDiscard;
use Override;
use Pulsar\Api\Internal;
use Pulsar\Compliance\Evidence\AiGovernanceDrillInterface;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Extension\AiGovernance\Config\AiGovernanceConfig;
use Pulsar\Extension\AiGovernance\Contracts\AiDataGovernanceInterface;
use Pulsar\Extension\AiGovernance\Contracts\AiImpactAssessmentInterface;
use Pulsar\Extension\AiGovernance\Contracts\AiLifecycleManagerInterface;
use Pulsar\Extension\AiGovernance\Contracts\AiModelRegistryInterface;
use Pulsar\Extension\AiGovernance\Contracts\ExplainabilityInterface;
use Pulsar\Extension\AiGovernance\Contracts\MonitoringRecordStoreInterface;
use Pulsar\Extension\AiGovernance\Dto\AiModel;
use Pulsar\Extension\AiGovernance\Dto\DataQualityReport;
use Pulsar\Extension\AiGovernance\Dto\DecisionFactor;
use Pulsar\Extension\AiGovernance\Dto\Explanation;
use Pulsar\Extension\AiGovernance\Enum\AiModelRiskLevel;
use Pulsar\Extension\AiGovernance\Enum\AiModelStatus;
use Pulsar\Extension\AiGovernance\Internal\Store\DbDataGovernanceStore;
use Pulsar\Extension\AiGovernance\Internal\Store\DbExplainabilityStore;
use Pulsar\Extension\AiGovernance\Internal\Store\DbImpactAssessmentStore;
use Pulsar\Extension\AiGovernance\Internal\Store\DbModelRegistry;
use Pulsar\Extension\AiGovernance\Internal\Store\DbMonitoringRecordStore;
use Pulsar\Extension\AiGovernance\Internal\Store\InMemoryDataGovernanceStore;
use Pulsar\Extension\AiGovernance\Internal\Store\InMemoryExplainabilityStore;
use Pulsar\Extension\AiGovernance\Internal\Store\InMemoryImpactAssessmentStore;
use Pulsar\Extension\AiGovernance\Internal\Store\InMemoryModelRegistry;
use Pulsar\Extension\AiGovernance\Internal\Store\InMemoryMonitoringRecordStore;

use function count;

/**
 * Lets the compliance assessor exercise this extension's governance record and
 * its monitoring.
 *
 * The framework cannot call the AI governance contracts itself: they belong to
 * this package, which is optional — trust tier `verified`, kind `product` — and
 * absent from the root autoload by ADR-0004, so a file under `src/` naming them
 * would make the framework depend on one of its own extensions.
 * {@see AiGovernanceDrillInterface} is declared in the framework for exactly that
 * reason and answered here, the same way {@see AiTransparencyDrill} answers the
 * Article 50 port.
 *
 * THE SECOND STORE INSTANCE IS THE WHOLE MECHANISM. Every `read...Back()` method
 * builds a NEW store of whatever kind this deployment configured, over the same
 * backing, and reads through that rather than through the instance that wrote.
 * The two share no memory, so a record the writer kept in a PHP array is simply
 * not there on the way back — which is how "usable for development, and evidence
 * of nothing after a restart" stops being a sentence in the documentation and
 * becomes a failing subject in the report. A durable store answers the same read
 * because the record left the process.
 *
 * WHAT IT CANNOT BUILD A SECOND COPY OF. A store an operator configured by class
 * name. Nothing here knows that class's constructor, so `fresh_instance` comes
 * back false, the read is served by the deployment's own instance, and the
 * observer says what that is worth. Reporting the fact is the point: a check that
 * quietly read through the writer would have called every store durable.
 *
 * IT MARSHALS AND IT DOES NOT JUDGE. Every method returns the record as the
 * subsystem stored it, plus the plain fact of which instance served the read.
 * Nothing here returns a status or an outcome; the comparisons that decide a
 * compliance fact are made in {@see \Pulsar\Compliance\Evidence\AiGovernanceRecordObserver}
 * and {@see \Pulsar\Compliance\Evidence\AiMonitoringObserver}, inside the
 * directory {@see \Pulsar\Compliance\Control\MeasuringComponent} seals.
 *
 * NOTHING HERE IS A TEST DOUBLE. It reaches the same bound contracts an
 * application reaches, through the container.
 *
 * @internal
 */
#[Internal(reason: 'Compliance seam over the AI governance contracts; those contracts are the public surface')]
final readonly class AiGovernanceDrill implements AiGovernanceDrillInterface
{
    public function __construct(
        private AiModelRegistryInterface $registry,
        private AiImpactAssessmentInterface $assessments,
        private AiDataGovernanceInterface $dataGovernance,
        private ExplainabilityInterface $explainability,
        private AiLifecycleManagerInterface $lifecycle,
        private MonitoringRecordStoreInterface $monitoringRecords,
        private AiGovernanceConfig $config,
        private ?ConnectionInterface $connection = null,
    ) {}

    #[Override]
    #[NoDiscard]
    public function configuredStores(): array
    {
        return [
            'registry' => $this->config->registryStore,
            'impact_assessment' => $this->config->impactAssessmentStore,
            'data_governance' => $this->config->dataGovernanceStore,
            'explainability' => $this->config->explainabilityStore,
            'monitoring_records' => $this->config->monitoringRecordStore,
        ];
    }

    #[Override]
    public function recordModel(
        string $modelId,
        string $name,
        string $version,
        string $provider,
        string $type,
        string $riskLevel,
        string $status,
        int $registeredAt,
    ): void {
        $this->registry->register(new AiModel(
            id: $modelId,
            name: $name,
            version: $version,
            provider: $provider,
            type: $type,
            riskLevel: AiModelRiskLevel::from($riskLevel),
            status: AiModelStatus::from($status),
            registeredAt: self::instant($registeredAt),
        ));
    }

    #[Override]
    #[NoDiscard]
    public function readModelBack(string $modelId): array
    {
        $fresh = $this->freshRegistry();
        $reader = $fresh ?? $this->registry;
        $model = $reader->get($modelId);

        return [
            'fresh_instance' => $fresh !== null,
            'store' => $this->config->registryStore,
            'inventory_size' => count($reader->all()),
            'record' => $model === null ? null : [
                'id' => $model->id,
                'name' => $model->name,
                'version' => $model->version,
                'provider' => $model->provider,
                'type' => $model->type,
                'risk_level' => $model->riskLevel->value,
                'status' => $model->status->value,
                'registered_at' => $model->registeredAt->getTimestamp(),
            ],
        ];
    }

    #[Override]
    public function openAssessment(string $modelId): void
    {
        $this->assessments->assess($modelId);
    }

    /**
     * @param non-empty-string $modelId
     *
     * @return array{fresh_instance: bool, store: string, assessed: bool, findings: int, risk_score: float}
     */
    #[Override]
    #[NoDiscard]
    public function readAssessmentBack(string $modelId): array
    {
        $fresh = $this->freshAssessments();
        $reader = $fresh ?? $this->assessments;

        return [
            'fresh_instance' => $fresh !== null,
            'store' => $this->config->impactAssessmentStore,
            'assessed' => $reader->hasAssessment($modelId),
            'findings' => count($reader->getFindings($modelId)),
            'risk_score' => $reader->getRiskScore($modelId),
        ];
    }

    #[Override]
    public function recordQualityReport(
        string $datasetId,
        int $assessedAt,
        float $completeness,
        float $accuracy,
        float $consistency,
        int $totalRecords,
        int $invalidRecords,
    ): void {
        $this->dataGovernance->recordQualityReport(new DataQualityReport(
            datasetId: $datasetId,
            assessedAt: self::instant($assessedAt),
            completeness: $completeness,
            accuracy: $accuracy,
            consistency: $consistency,
            totalRecords: $totalRecords,
            invalidRecords: $invalidRecords,
        ));
    }

    #[Override]
    #[NoDiscard]
    public function readQualityReportBack(string $datasetId): array
    {
        $fresh = $this->freshDataGovernance();
        $reader = $fresh ?? $this->dataGovernance;
        $report = $reader->getLatestQualityReport($datasetId);

        return [
            'fresh_instance' => $fresh !== null,
            'store' => $this->config->dataGovernanceStore,
            'report' => $report === null ? null : [
                'dataset_id' => $report->datasetId,
                'assessed_at' => $report->assessedAt->getTimestamp(),
                'completeness' => $report->completeness,
                'accuracy' => $report->accuracy,
                'consistency' => $report->consistency,
                'total_records' => $report->totalRecords,
                'invalid_records' => $report->invalidRecords,
            ],
        ];
    }

    #[Override]
    public function recordExplanation(
        string $decisionId,
        string $modelId,
        string $summary,
        float $confidence,
        int $generatedAt,
        string $factorName,
        float $factorWeight,
        string $factorDescription,
    ): void {
        $this->explainability->record(new Explanation(
            decisionId: $decisionId,
            modelId: $modelId,
            summary: $summary,
            factors: [new DecisionFactor($factorName, $factorWeight, $factorDescription)],
            confidence: $confidence,
            generatedAt: self::instant($generatedAt),
        ));
    }

    /**
     * @param non-empty-string $decisionId
     *
     * @return array{
     *     fresh_instance: bool,
     *     store: string,
     *     by_model: int,
     *     explanation: null|array{
     *         decision_id: string,
     *         model_id: string,
     *         summary: string,
     *         confidence: float,
     *         generated_at: int,
     *         factors: list<array{name: string, weight: float, description: string}>,
     *     },
     * }
     */
    #[Override]
    #[NoDiscard]
    public function readExplanationBack(string $decisionId): array
    {
        $fresh = $this->freshExplainability();
        $reader = $fresh ?? $this->explainability;
        $explanation = $reader->explain($decisionId);

        $factors = [];

        // Written as an explicit absence check rather than `$explanation?->factors ?? []`:
        // `??` already suppresses the property read on null, so the nullsafe operator
        // in front of it says nothing, and "there is no explanation" is the answer
        // this drill exists to be able to report.
        if ($explanation !== null) {
            foreach ($explanation->factors as $factor) {
                $factors[] = [
                    'name' => $factor->name,
                    'weight' => $factor->weight,
                    'description' => $factor->description,
                ];
            }
        }

        return [
            'fresh_instance' => $fresh !== null,
            'store' => $this->config->explainabilityStore,
            'by_model' => $explanation === null ? 0 : count($reader->getByModel($explanation->modelId)),
            'explanation' => $explanation === null ? null : [
                'decision_id' => $explanation->decisionId,
                'model_id' => $explanation->modelId,
                'summary' => $explanation->summary,
                'confidence' => $explanation->confidence,
                'generated_at' => $explanation->generatedAt->getTimestamp(),
                'factors' => $factors,
            ],
        ];
    }

    #[Override]
    #[NoDiscard]
    public function runMonitoring(string $modelId): array
    {
        $results = [];

        foreach ($this->lifecycle->monitor($modelId) as $result) {
            $results[] = [
                'hook' => $result->hookName,
                'healthy' => $result->healthy,
                'message' => $result->message,
                'metrics' => $result->metrics,
            ];
        }

        return $results;
    }

    #[Override]
    #[NoDiscard]
    public function readRetainedMonitoringBack(string $modelId): array
    {
        $fresh = $this->freshMonitoringRecords();
        $reader = $fresh ?? $this->monitoringRecords;

        $records = [];

        foreach ($reader->forModel($modelId) as $record) {
            $records[] = [
                'hook' => $record->hookName,
                'healthy' => $record->healthy,
                'message' => $record->message,
                'observed_at' => $record->observedAt->getTimestamp(),
            ];
        }

        return [
            'fresh_instance' => $fresh !== null,
            'store' => $this->config->monitoringRecordStore,
            'retained' => $reader->countForModel($modelId),
            'records' => $records,
        ];
    }

    #[Override]
    #[NoDiscard]
    public function purgeRetainedMonitoring(string $modelId): int
    {
        return $this->monitoringRecords->purgeForModel($modelId);
    }

    private function freshRegistry(): ?AiModelRegistryInterface
    {
        return match ($this->config->registryStore) {
            AiGovernanceConfig::MEMORY => new InMemoryModelRegistry(),
            AiGovernanceConfig::DATABASE => $this->connection === null
                ? null
                : new DbModelRegistry($this->connection),
            default => null,
        };
    }

    private function freshAssessments(): ?AiImpactAssessmentInterface
    {
        return match ($this->config->impactAssessmentStore) {
            AiGovernanceConfig::MEMORY => new InMemoryImpactAssessmentStore(),
            AiGovernanceConfig::DATABASE => $this->connection === null
                ? null
                : new DbImpactAssessmentStore($this->connection),
            default => null,
        };
    }

    private function freshDataGovernance(): ?AiDataGovernanceInterface
    {
        return match ($this->config->dataGovernanceStore) {
            AiGovernanceConfig::MEMORY => new InMemoryDataGovernanceStore(),
            AiGovernanceConfig::DATABASE => $this->connection === null
                ? null
                : new DbDataGovernanceStore($this->connection),
            default => null,
        };
    }

    private function freshExplainability(): ?ExplainabilityInterface
    {
        return match ($this->config->explainabilityStore) {
            AiGovernanceConfig::MEMORY => new InMemoryExplainabilityStore(),
            AiGovernanceConfig::DATABASE => $this->connection === null
                ? null
                : new DbExplainabilityStore($this->connection),
            default => null,
        };
    }

    private function freshMonitoringRecords(): ?MonitoringRecordStoreInterface
    {
        return match ($this->config->monitoringRecordStore) {
            AiGovernanceConfig::MEMORY => new InMemoryMonitoringRecordStore(),
            AiGovernanceConfig::DATABASE => $this->connection === null
                ? null
                : new DbMonitoringRecordStore($this->connection),
            default => null,
        };
    }

    private static function instant(int $unixSeconds): DateTimeImmutable
    {
        return new DateTimeImmutable('@' . $unixSeconds)->setTimezone(new DateTimeZone('UTC'));
    }
}
