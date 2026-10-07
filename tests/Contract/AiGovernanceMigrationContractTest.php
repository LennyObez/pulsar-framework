<?php

declare(strict_types=1);

namespace Pulsar\Tests\Contract;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Driver;
use Pulsar\Extension\AiGovernance\Dto\AiModel;
use Pulsar\Extension\AiGovernance\Dto\DataProvenance;
use Pulsar\Extension\AiGovernance\Dto\DataQualityReport;
use Pulsar\Extension\AiGovernance\Dto\DecisionFactor;
use Pulsar\Extension\AiGovernance\Dto\Explanation;
use Pulsar\Extension\AiGovernance\Dto\MonitoringRecord;
use Pulsar\Extension\AiGovernance\Enum\AiModelRiskLevel;
use Pulsar\Extension\AiGovernance\Enum\AiModelStatus;
use Pulsar\Extension\AiGovernance\Enum\ImpactCategory;
use Pulsar\Extension\AiGovernance\Internal\Store\DbAiTransparency;
use Pulsar\Extension\AiGovernance\Internal\Store\DbDataGovernanceStore;
use Pulsar\Extension\AiGovernance\Internal\Store\DbExplainabilityStore;
use Pulsar\Extension\AiGovernance\Internal\Store\DbHumanOversight;
use Pulsar\Extension\AiGovernance\Internal\Store\DbImpactAssessmentStore;
use Pulsar\Extension\AiGovernance\Internal\Store\DbModelRegistry;
use Pulsar\Extension\AiGovernance\Internal\Store\DbMonitoringRecordStore;
use Pulsar\Extension\AiGovernance\Oversight\OversightAction;
use Pulsar\Extension\AiGovernance\Oversight\OversightAssignment;
use Pulsar\Extension\AiGovernance\Oversight\OversightCapability;
use Pulsar\Extension\AiGovernance\Oversight\OversightIntervention;
use Pulsar\Extension\AiGovernance\Transparency\AiInteractionDisclosure;
use Pulsar\Extension\AiGovernance\Transparency\AiTransparencyPolicy;
use Pulsar\Tests\Support\FrameworkSchema;
use Throwable;

/**
 * The three ai-governance migrations on every engine, judged by each engine's catalogue and by
 * the real stores reading back what they wrote: width, collation, instants, booleans, keys and
 * a second run. SQLite alone cannot see a narrowed FLOAT, a folded key or MySQL's DATETIME(6).
 */
final class AiGovernanceMigrationContractTest extends SchemaMigrationContractTestCase
{
    private const array MIGRATIONS = [
        'extensions/ai-governance/src/Migration/20260828000001_create_ai_governance_records.php',
        'extensions/ai-governance/src/Migration/20260902000001_create_ai_transparency_policies.php',
        'extensions/ai-governance/src/Migration/20260902000002_create_ai_oversight_records.php',
    ];

    /** Character columns per table, the ones MySQL must collate byte-exactly. */
    private const array CHARACTER_COLUMNS = [
        'ai_models' => ['id', 'name', 'version', 'provider', 'type', 'risk_level', 'status', 'previous_status', 'actor_role'],
        'ai_impact_assessments' => ['model_id'],
        'ai_impact_findings' => ['model_id', 'finding_id', 'category', 'severity', 'title', 'description', 'recommendation'],
        'ai_data_provenance' => ['id', 'dataset_id', 'source', 'data_type', 'consent_reference', 'license'],
        'ai_data_quality_reports' => ['dataset_id'],
        'ai_explanations' => ['decision_id', 'model_id', 'summary'],
        'ai_monitoring_records' => ['model_id', 'hook_name', 'message'],
        'ai_transparency_policies' => ['surface_id', 'disclosure_notice', 'disclosure_locale', 'exemption'],
        'ai_oversight_assignments' => ['model_id', 'overseer_id', 'competence_basis', 'authority_basis'],
        'ai_oversight_interventions' => ['intervention_id', 'model_id', 'overseer_id', 'action', 'rationale', 'decision_id'],
    ];

    private const array INDEXES = [
        ['ai_models', 'idx_ai_models_status'],
        ['ai_models', 'idx_ai_models_risk_level'],
        ['ai_data_provenance', 'idx_ai_data_provenance_dataset'],
        ['ai_explanations', 'idx_ai_explanations_model'],
        ['ai_monitoring_records', 'idx_ai_monitoring_model_observed'],
        ['ai_oversight_interventions', 'idx_ai_oversight_model_occurred'],
        ['ai_oversight_interventions', 'idx_ai_oversight_overseer'],
    ];

    private const int INSTANT = 1_785_628_800;

    protected function tables(): array
    {
        return [
            'ai_oversight_interventions',
            'ai_oversight_assignments',
            'ai_transparency_policies',
            'ai_monitoring_records',
            'ai_explanations',
            'ai_data_quality_reports',
            'ai_data_provenance',
            'ai_impact_findings',
            'ai_impact_assessments',
            'ai_models',
        ];
    }

    protected function migrationPath(): string
    {
        return self::MIGRATIONS[0];
    }

    #[Test]
    #[DataProvider('engines')]
    public function everyTableTheStoresWriteExists(Driver $driver): void
    {
        $connection = $this->applied($driver);

        foreach ($this->tables() as $table) {
            self::assertTrue($this->tableExists($connection, $table), $table);
        }
        self::assertTrue($this->columnExists($connection, 'ai_models', 'actor_role'));
    }

    #[Test]
    #[DataProvider('engines')]
    public function aScoreKeepsEveryDigitItWasWrittenWith(Driver $driver): void
    {
        $connection = $this->applied($driver);

        if ($driver === Driver::MySQL) {
            foreach (['completeness', 'accuracy', 'consistency'] as $column) {
                self::assertSame('double', $this->columnType($connection, 'ai_data_quality_reports', $column));
            }
            self::assertSame('double', $this->columnType($connection, 'ai_explanations', 'confidence'));
        }

        new DbDataGovernanceStore($connection)->recordQualityReport($this->qualityReport('ds1', 0.123456789));
        new DbExplainabilityStore($connection)->record($this->explanation('d1', 0.123456789));

        $report = new DbDataGovernanceStore($connection)->getLatestQualityReport('ds1');
        self::assertNotNull($report);
        self::assertSame(0.123456789, $report->completeness);
        self::assertSame(0.123456789, $report->accuracy);
        self::assertSame(0.123456789, $report->consistency);
        self::assertSame(0.123456789, new DbExplainabilityStore($connection)->explain('d1')?->confidence);
    }

    #[Test]
    #[DataProvider('engines')]
    public function anInstantReadsBackAsTheInstantWritten(Driver $driver): void
    {
        $connection = $this->applied($driver);

        new DbModelRegistry($connection)->register($this->model('m1'));
        new DbMonitoringRecordStore($connection)->record($this->monitoring('m1', true));
        $this->interveneOn($connection, 'i1');

        self::assertSame(self::INSTANT, new DbModelRegistry($connection)->get('m1')?->registeredAt->getTimestamp());
        self::assertSame(self::INSTANT, new DbMonitoringRecordStore($connection)->forModel('m1')[0]->observedAt->getTimestamp());
        $oversight = new DbHumanOversight($connection);
        self::assertSame(self::INSTANT, $oversight->assignmentsFor('m1')[0]->assignedAt->getTimestamp());
        self::assertSame(self::INSTANT, $oversight->interventionsFor('m1')[0]->occurredAt->getTimestamp());
    }

    #[Test]
    #[DataProvider('engines')]
    public function aBooleanReadsBackAsTheBooleanWritten(Driver $driver): void
    {
        $connection = $this->applied($driver);

        $governance = new DbDataGovernanceStore($connection);
        $governance->recordProvenance($this->provenance('p-yes', 'ds-yes', true));
        $governance->recordProvenance($this->provenance('p-no', 'ds-no', false));
        self::assertTrue(new DbDataGovernanceStore($connection)->getProvenance('ds-yes')[0]->consentObtained);
        self::assertFalse(new DbDataGovernanceStore($connection)->getProvenance('ds-no')[0]->consentObtained);

        $monitoring = new DbMonitoringRecordStore($connection);
        $monitoring->record($this->monitoring('m-up', true));
        $monitoring->record($this->monitoring('m-down', false));
        self::assertTrue(new DbMonitoringRecordStore($connection)->forModel('m-up')[0]->healthy);
        self::assertFalse(new DbMonitoringRecordStore($connection)->forModel('m-down')[0]->healthy);

        $transparency = new DbAiTransparency($connection);
        $transparency->declare(new AiTransparencyPolicy(
            surfaceId: 'tipline',
            interactsWithNaturalPersons: true,
            disclosure: new AiInteractionDisclosure('You are interacting with an AI system.', 'en'),
            publicCrimeReporting: true,
        ));
        $transparency->declare(new AiTransparencyPolicy(surfaceId: 'batch', interactsWithNaturalPersons: false));
        $tipline = new DbAiTransparency($connection)->policyFor('tipline');
        $batch = new DbAiTransparency($connection)->policyFor('batch');
        self::assertNotNull($tipline);
        self::assertNotNull($batch);
        self::assertTrue($tipline->interactsWithNaturalPersons);
        self::assertTrue($tipline->publicCrimeReporting);
        self::assertFalse($batch->interactsWithNaturalPersons);
        self::assertFalse($batch->publicCrimeReporting);
    }

    #[Test]
    #[DataProvider('engines')]
    public function everyTableComparesItsKeysExactly(Driver $driver): void
    {
        $connection = $this->applied($driver);

        foreach (self::CHARACTER_COLUMNS as $table => $columns) {
            $this->assertExactCollation($connection, $table, $columns);
        }

        $this->assertCaseVariantKeysCoexist($connection, 'ai_models', 'id', function (string $id) use ($connection): void {
            new DbModelRegistry($connection)->register($this->model(self::key($id)));
        });
        $this->assertCaseVariantKeysCoexist($connection, 'ai_impact_assessments', 'model_id', static function (string $id) use ($connection): void {
            new DbImpactAssessmentStore($connection)->assess(self::key($id), [ImpactCategory::Fairness]);
        });
        $this->assertCaseVariantKeysCoexist($connection, 'ai_data_provenance', 'id', function (string $id) use ($connection): void {
            new DbDataGovernanceStore($connection)->recordProvenance($this->provenance(self::key($id), 'ds1', true));
        });
        $this->assertCaseVariantKeysCoexist($connection, 'ai_data_quality_reports', 'dataset_id', function (string $id) use ($connection): void {
            new DbDataGovernanceStore($connection)->recordQualityReport($this->qualityReport(self::key($id), 0.5));
        });
        $this->assertCaseVariantKeysCoexist($connection, 'ai_explanations', 'decision_id', function (string $id) use ($connection): void {
            new DbExplainabilityStore($connection)->record($this->explanation(self::key($id), 0.5));
        });
        $this->assertCaseVariantKeysCoexist($connection, 'ai_transparency_policies', 'surface_id', static function (string $id) use ($connection): void {
            new DbAiTransparency($connection)->declare(new AiTransparencyPolicy(surfaceId: self::key($id), interactsWithNaturalPersons: false));
        });
        $this->assertCaseVariantKeysCoexist($connection, 'ai_oversight_interventions', 'intervention_id', function (string $id) use ($connection): void {
            $this->interveneOn($connection, self::key($id));
        });
    }

    #[Test]
    #[DataProvider('engines')]
    public function theFindingKeyIsCompositeAndMonitoringIdsAreAssigned(Driver $driver): void
    {
        $connection = $this->applied($driver);
        $insert = 'INSERT INTO ai_impact_findings (model_id, finding_id, category, severity, title, description, recommendation, recorded_at)'
            . " VALUES ('m1', 'f1', 'fairness', 'low', 't', 'd', 'r', '2026-08-28 10:54:00')";
        $connection->execute($insert);

        try {
            $connection->execute($insert);
            self::fail('a second (model_id, finding_id) = (m1, f1) row was accepted: the key is not composite');
        } catch (Throwable) {
            self::assertSame(1, $this->rowCount($connection, 'ai_impact_findings'));
        }

        $store = new DbMonitoringRecordStore($connection);
        $store->record($this->monitoring('m1', true));
        $store->record($this->monitoring('m1', false));
        $ids = [];
        foreach ($connection->query('SELECT id FROM ai_monitoring_records')->rows as $row) {
            $ids[] = $row->getInt('id');
        }
        self::assertCount(2, array_unique($ids), 'each monitoring result gets its own surrogate key');
    }

    #[Test]
    #[DataProvider('engines')]
    public function aSecondRunChangesNothingAndKeepsEveryIndexName(Driver $driver): void
    {
        $connection = $this->applied($driver);
        new DbModelRegistry($connection)->register($this->model('m1'));

        FrameworkSchema::up($connection, ...self::MIGRATIONS);

        self::assertSame(1, $this->rowCount($connection, 'ai_models'));
        foreach (self::INDEXES as [$table, $index]) {
            self::assertTrue($this->indexExists($connection, $table, $index), $index . ' under its exact, unprefixed name');
        }
    }

    private function applied(Driver $driver): ConnectionInterface
    {
        $connection = $this->engine($driver);
        FrameworkSchema::up($connection, ...self::MIGRATIONS);

        return $connection;
    }

    /** @param non-empty-string $interventionId */
    private function interveneOn(ConnectionInterface $connection, string $interventionId): void
    {
        $oversight = new DbHumanOversight($connection);
        $oversight->assign(new OversightAssignment(
            modelId: 'm1',
            overseerId: 'o1',
            competenceBasis: 'Trained on the model card.',
            authorityBasis: 'Head of the review desk.',
            capabilities: OversightCapability::cases(),
            assignedAt: $this->instant(),
        ));
        $oversight->recordIntervention(new OversightIntervention(
            interventionId: $interventionId,
            modelId: 'm1',
            overseerId: 'o1',
            action: OversightAction::DisregardedOutput,
            rationale: 'The ranking ignored a documented exception.',
            occurredAt: $this->instant(),
        ));
    }

    /** @param non-empty-string $id */
    private function model(string $id): AiModel
    {
        return new AiModel(
            id: $id,
            name: 'Model',
            version: '1.0.0',
            provider: 'acme',
            type: 'llm',
            riskLevel: AiModelRiskLevel::High,
            status: AiModelStatus::Staging,
            registeredAt: $this->instant(),
        );
    }

    /**
     * @param non-empty-string $id
     * @param non-empty-string $datasetId
     */
    private function provenance(string $id, string $datasetId, bool $consent): DataProvenance
    {
        return new DataProvenance(
            id: $id,
            datasetId: $datasetId,
            source: 'vendor://acme/archive',
            dataType: 'structured',
            collectedAt: $this->instant(),
            consentObtained: $consent,
            consentReference: $consent ? 'consent-1' : null,
            license: 'MIT',
            transformations: [],
            qualityMetrics: [],
        );
    }

    /** @param non-empty-string $datasetId */
    private function qualityReport(string $datasetId, float $score): DataQualityReport
    {
        return new DataQualityReport(
            datasetId: $datasetId,
            assessedAt: $this->instant(),
            completeness: $score,
            accuracy: $score,
            consistency: $score,
            totalRecords: 10,
            invalidRecords: 0,
            issues: [],
        );
    }

    /** @param non-empty-string $decisionId */
    private function explanation(string $decisionId, float $confidence): Explanation
    {
        return new Explanation(
            decisionId: $decisionId,
            modelId: 'm1',
            summary: 'Ranked below the threshold.',
            factors: [new DecisionFactor('experience', 0.6, 'Weighted most.')],
            confidence: $confidence,
            generatedAt: $this->instant(),
            alternativesConsidered: [],
        );
    }

    /** @param non-empty-string $modelId */
    private function monitoring(string $modelId, bool $healthy): MonitoringRecord
    {
        return new MonitoringRecord(
            modelId: $modelId,
            hookName: 'governance_conformity',
            healthy: $healthy,
            message: $healthy ? 'conforms' : 'breach',
            metrics: [],
            observedAt: $this->instant(),
        );
    }

    /**
     * The key the case-variant helper hands a writer, typed as the stores require.
     *
     * @return non-empty-string
     */
    private static function key(string $id): string
    {
        if ($id === '') {
            self::fail('the case-variant helper handed out an empty key');
        }

        return $id;
    }

    private function instant(): DateTimeImmutable
    {
        return new DateTimeImmutable('@' . self::INSTANT)->setTimezone(new DateTimeZone('UTC'));
    }
}
