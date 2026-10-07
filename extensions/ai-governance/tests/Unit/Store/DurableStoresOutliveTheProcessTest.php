<?php

declare(strict_types=1);

namespace Pulsar\Extension\AiGovernance\Tests\Unit\Store;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\PdoConnection;
use Pulsar\Extension\AiGovernance\Dto\AiModel;
use Pulsar\Extension\AiGovernance\Dto\DataProvenance;
use Pulsar\Extension\AiGovernance\Dto\DataQualityReport;
use Pulsar\Extension\AiGovernance\Dto\DecisionFactor;
use Pulsar\Extension\AiGovernance\Dto\Explanation;
use Pulsar\Extension\AiGovernance\Dto\ImpactFinding;
use Pulsar\Extension\AiGovernance\Dto\ModelCard;
use Pulsar\Extension\AiGovernance\Dto\MonitoringRecord;
use Pulsar\Extension\AiGovernance\Enum\AiActorRole;
use Pulsar\Extension\AiGovernance\Enum\AiModelRiskLevel;
use Pulsar\Extension\AiGovernance\Enum\AiModelStatus;
use Pulsar\Extension\AiGovernance\Enum\ImpactCategory;
use Pulsar\Extension\AiGovernance\Enum\ImpactSeverity;
use Pulsar\Extension\AiGovernance\Exception\AiGovernanceException;
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
use Pulsar\Extension\AiGovernance\Internal\Store\StoredValue;
use Pulsar\Extension\AiGovernance\Tests\Support\AiGovernanceSchema;

/**
 * The AI management system record survives the object that wrote it — and the
 * development stores do not.
 *
 * EVERY ASSERTION HERE READS THROUGH A SECOND STORE INSTANCE, never through the
 * one that performed the write. That is the only thing separating a store that
 * retains from one that remembers, and it is what ISO 42001 Clause 7.5 asks about
 * when it says documented information: a record no second reader can see is not
 * documentation of anything.
 *
 * The `theInMemory...` cases are the same assertions with the shipped development
 * store in place of the durable one, and they assert the OPPOSITE. They are here
 * so this file measures the difference rather than describing it: before rc.12
 * the in-memory stores were what every deployment got by default, and the
 * framework's own documentation called them evidence of nothing after a restart.
 */
#[CoversClass(DbModelRegistry::class)]
#[CoversClass(DbImpactAssessmentStore::class)]
#[CoversClass(DbDataGovernanceStore::class)]
#[CoversClass(DbExplainabilityStore::class)]
#[CoversClass(DbMonitoringRecordStore::class)]
#[CoversClass(InMemoryMonitoringRecordStore::class)]
#[CoversClass(StoredValue::class)]
final class DurableStoresOutliveTheProcessTest extends TestCase
{
    private const int INSTANT = 1_785_628_800;

    private PdoConnection $connection;

    protected function setUp(): void
    {
        $this->connection = AiGovernanceSchema::connection();
    }

    // --- The model inventory --------------------------------------------------

    #[Test]
    public function theModelInventorySurvivesTheRegistryThatWroteIt(): void
    {
        $registry = new DbModelRegistry($this->connection);
        $registry->register($this->model());

        $back = $this->freshRegistry()->get('m1');

        self::assertNotNull($back, 'the inventory entry must be visible to a registry that did not write it');
        self::assertSame('m1', $back->id);
        self::assertSame('Model One', $back->name);
        self::assertSame('1.2.3', $back->version);
        self::assertSame('acme', $back->provider);
        self::assertSame('llm', $back->type);
        self::assertSame(AiModelRiskLevel::High, $back->riskLevel);
        self::assertSame(AiModelStatus::Staging, $back->status);

        // The instant is the one that was handed in, not the one the row was
        // written at: a registry that stamped its own clock would be recording
        // the time of storage as the time of registration.
        self::assertSame(self::INSTANT, $back->registeredAt->getTimestamp());
    }

    #[Test]
    public function theInMemoryRegistryLosesTheInventoryWithTheInstanceThatHeldIt(): void
    {
        $registry = new InMemoryModelRegistry();
        $registry->register($this->model());

        self::assertNotNull($registry->get('m1'), 'the writer itself can still see it');
        self::assertNull(
            new InMemoryModelRegistry()->get('m1'),
            'and nothing else ever can, which is why it was the wrong default',
        );
    }

    /**
     * The EU AI Act role survives, and absence survives as absence.
     *
     * The role decides which obligations the high-risk gate enforces, so a
     * registry that dropped it would send every restarted deployment back to
     * "declared neither role" — and one that substituted a default would answer
     * the legal question on the operator's behalf, permanently, in a column.
     */
    #[Test]
    public function theDeclaredActorRoleSurvivesTheRegistryThatWroteIt(): void
    {
        new DbModelRegistry($this->connection)->register(
            $this->model()->withActorRole(AiActorRole::Deployer),
        );

        self::assertSame(AiActorRole::Deployer, $this->freshRegistry()->get('m1')?->actorRole);
    }

    #[Test]
    public function anUndeclaredActorRoleComesBackUndeclared(): void
    {
        new DbModelRegistry($this->connection)->register($this->model());

        $back = $this->freshRegistry()->get('m1');

        self::assertNotNull($back);
        self::assertNull($back->actorRole, 'nobody decided, and the row must not decide for them');
    }

    #[Test]
    public function aStoredRoleTheActDoesNotDefineIsACorruptRecord(): void
    {
        new DbModelRegistry($this->connection)->register($this->model());

        $this->connection->execute(
            "UPDATE ai_models SET actor_role = 'operator' WHERE id = 'm1'",
        );

        // Read as absence it would silently downgrade a stated role to an
        // unstated one, and the deployment would then be refused for a question
        // it had already answered.
        $this->expectException(AiGovernanceException::class);
        $this->expectExceptionMessageMatches('/actor_role/');

        (void) $this->freshRegistry()->get('m1');
    }

    #[Test]
    public function theModelCardSurvivesWithEveryFieldItCarried(): void
    {
        new DbModelRegistry($this->connection)->register($this->model($this->card()));

        $card = $this->freshRegistry()->get('m1')?->card;

        self::assertNotNull($card);
        self::assertSame('What the model does', $card->description);
        self::assertSame('Who may use it and for what', $card->intendedUse);
        self::assertSame(['ranks applications'], $card->capabilities);
        self::assertSame(['English only'], $card->limitations);
        self::assertSame(['under-represents applicants over 60'], $card->knownBiases);
        self::assertSame(['internal HR archive'], $card->trainingDataSources);
        self::assertSame(['auc' => 0.91], $card->performanceMetrics);
        self::assertSame(['human review is mandatory'], $card->ethicalConsiderations);
    }

    #[Test]
    public function theRegistryFiltersByStatusAndRiskLevelThroughTheDatabase(): void
    {
        $registry = new DbModelRegistry($this->connection);
        $registry->register($this->model());
        $registry->register($this->model(id: 'm2', risk: AiModelRiskLevel::Minimal));

        $fresh = $this->freshRegistry();

        self::assertCount(2, $fresh->all());
        self::assertCount(2, $fresh->byStatus(AiModelStatus::Staging));
        self::assertCount(1, $fresh->byRiskLevel(AiModelRiskLevel::High));
        self::assertCount(0, $fresh->byStatus(AiModelStatus::Retired));
    }

    #[Test]
    public function registeringTheSameIdTwiceReplacesTheEntryRatherThanAddingOne(): void
    {
        $registry = new DbModelRegistry($this->connection);
        $registry->register($this->model());
        $registry->register($this->model(name: 'Model One, corrected'));

        $fresh = $this->freshRegistry();

        self::assertCount(1, $fresh->all(), 'a model id is documented as unique, so a rewrite is a correction');
        self::assertSame('Model One, corrected', $fresh->get('m1')?->name);
    }

    #[Test]
    public function theArticle5RefusalIsHeldByTheDurableRegistryToo(): void
    {
        $registry = new DbModelRegistry($this->connection);

        $this->expectException(AiGovernanceException::class);
        $this->expectExceptionMessageIsOrContains('Article 5');

        $registry->register($this->model(
            risk: AiModelRiskLevel::Unacceptable,
            status: AiModelStatus::Production,
        ));
    }

    #[Test]
    public function transitioningIntoProductionIsRefusedForAProhibitedPractice(): void
    {
        $registry = new DbModelRegistry($this->connection);
        $registry->register($this->model(risk: AiModelRiskLevel::Unacceptable));

        $this->expectException(AiGovernanceException::class);
        $this->expectExceptionMessageIsOrContains('Article 5');

        $registry->transitionStatus('m1', AiModelStatus::Production);
    }

    #[Test]
    public function anInvalidTransitionIsRefusedAndTheStateMachineIsTheReason(): void
    {
        $registry = new DbModelRegistry($this->connection);
        $registry->register($this->model(status: AiModelStatus::Development));

        $this->expectException(AiGovernanceException::class);
        $this->expectExceptionMessageIsOrContains('development');

        $registry->transitionStatus('m1', AiModelStatus::Production);
    }

    #[Test]
    public function thePreviousStatusOutlivesTheTransitionThatProducedIt(): void
    {
        $registry = new DbModelRegistry($this->connection);
        $registry->register($this->model(status: AiModelStatus::Staging));
        $registry->transitionStatus('m1', AiModelStatus::Production);

        // The in-memory registry keeps this in a second array, so a rollback
        // attempted by a worker other than the one that deployed had nothing to
        // roll back to.
        self::assertSame(AiModelStatus::Staging, $this->freshRegistry()->getPreviousStatus('m1'));
    }

    #[Test]
    public function reclassifyingALiveModelAsProhibitedWithdrawsItAndTheWithdrawalIsDurable(): void
    {
        $registry = new DbModelRegistry($this->connection);
        $registry->register($this->model(status: AiModelStatus::Staging));
        $registry->transitionStatus('m1', AiModelStatus::Production);

        $updated = $registry->updateRiskLevel('m1', AiModelRiskLevel::Unacceptable);

        self::assertSame(AiModelStatus::Deprecated, $updated->status);
        self::assertSame(AiModelStatus::Deprecated, $this->freshRegistry()->get('m1')?->status);
        self::assertSame(AiModelRiskLevel::Unacceptable, $this->freshRegistry()->get('m1')?->riskLevel);
    }

    #[Test]
    public function aCorruptModelRowIsReportedRatherThanRepaired(): void
    {
        new DbModelRegistry($this->connection)->register($this->model());
        $this->connection->execute("UPDATE ai_models SET name = '' WHERE id = 'm1'");

        // A store that substituted a placeholder would hand an assessor a
        // governance record the deployment never wrote, which is worse than a
        // read that fails visibly.
        $this->expectException(AiGovernanceException::class);
        $this->expectExceptionMessageIsOrContains('ai_models');

        $this->freshRegistry()->get('m1');
    }

    // --- Impact assessments ---------------------------------------------------

    #[Test]
    public function anOpenedAssessmentIsDistinguishableFromNeverHavingLooked(): void
    {
        new DbImpactAssessmentStore($this->connection)->assess('m1', [ImpactCategory::Fairness]);

        $fresh = new DbImpactAssessmentStore($this->connection);

        self::assertTrue($fresh->hasAssessment('m1'), 'assessed with nothing adverse found');
        self::assertFalse($fresh->hasAssessment('m2'), 'never assessed');
        self::assertSame([], $fresh->getFindings('m1'));
        self::assertSame(0.0, $fresh->getRiskScore('m1'));
    }

    #[Test]
    public function theInMemoryAssessmentStoreForgetsThatTheModelWasAssessed(): void
    {
        $store = new InMemoryImpactAssessmentStore();
        $store->assess('m1');

        self::assertTrue($store->hasAssessment('m1'));
        self::assertFalse(
            new InMemoryImpactAssessmentStore()->hasAssessment('m1'),
            'so the high-risk deployment gate reads "never assessed" after a restart',
        );
    }

    #[Test]
    public function findingsSurviveAndTheRiskScoreIsComputedFromThem(): void
    {
        $store = new DbImpactAssessmentStore($this->connection);
        $store->addFinding('m1', $this->finding('f1', ImpactSeverity::High));
        $store->addFinding('m1', $this->finding('f2', ImpactSeverity::Low));

        $fresh = new DbImpactAssessmentStore($this->connection);

        self::assertTrue($fresh->hasAssessment('m1'), 'adding a finding opens the assessment');
        self::assertCount(2, $fresh->getFindings('m1'));
        self::assertSame(3.5, $fresh->getRiskScore('m1'), 'the weights match the development store exactly');
    }

    #[Test]
    public function replayingTheSameFindingCorrectsItRatherThanInflatingTheScore(): void
    {
        $store = new DbImpactAssessmentStore($this->connection);
        $store->addFinding('m1', $this->finding('f1', ImpactSeverity::Critical));
        $store->addFinding('m1', $this->finding('f1', ImpactSeverity::Low));

        $fresh = new DbImpactAssessmentStore($this->connection);

        self::assertCount(1, $fresh->getFindings('m1'), 'a finding id is documented as unique');
        self::assertSame(1.0, $fresh->getRiskScore('m1'));
    }

    // --- Data governance ------------------------------------------------------

    #[Test]
    public function provenanceAndItsConsentPositionSurviveTheStoreThatWroteThem(): void
    {
        new DbDataGovernanceStore($this->connection)->recordProvenance($this->provenance());

        $fresh = new DbDataGovernanceStore($this->connection);
        $records = $fresh->getProvenance('ds1');

        self::assertCount(1, $records);
        self::assertSame('vendor://acme/hr-archive', $records[0]->source);
        self::assertSame('MIT', $records[0]->license);
        self::assertSame('consent-2026-01', $records[0]->consentReference);
        self::assertSame(['deduplicated'], $records[0]->transformations);
        self::assertSame(['rows' => 1000], $records[0]->qualityMetrics);
        self::assertTrue($fresh->isConsentComplete('ds1'));
    }

    #[Test]
    public function aDatasetWithNoProvenanceRecordHasNoConsentBasis(): void
    {
        // "Nothing is recorded" is not a basis. Answering true for an empty set
        // would make an untracked dataset indistinguishable from a consented one.
        self::assertFalse(new DbDataGovernanceStore($this->connection)->isConsentComplete('unknown'));
    }

    #[Test]
    public function oneUnconsentedSourceIsEnoughToMakeTheDatasetIncomplete(): void
    {
        $store = new DbDataGovernanceStore($this->connection);
        $store->recordProvenance($this->provenance());
        $store->recordProvenance($this->provenance(id: 'p2', consent: false));

        self::assertFalse(new DbDataGovernanceStore($this->connection)->isConsentComplete('ds1'));
    }

    #[Test]
    public function theQualityReportSurvivesWithEveryMeasureItCarried(): void
    {
        new DbDataGovernanceStore($this->connection)->recordQualityReport($this->qualityReport());

        $report = new DbDataGovernanceStore($this->connection)->getLatestQualityReport('ds1');

        self::assertNotNull($report);
        self::assertSame(99.5, $report->completeness);
        self::assertSame(98.25, $report->accuracy);
        self::assertSame(97.0, $report->consistency);
        self::assertSame(1000, $report->totalRecords);
        self::assertSame(7, $report->invalidRecords);
        self::assertSame(['12 rows missing a locale'], $report->issues);
        self::assertSame(self::INSTANT, $report->assessedAt->getTimestamp());
    }

    #[Test]
    public function theInMemoryDataGovernanceStoreLosesTheConsentPosition(): void
    {
        $store = new InMemoryDataGovernanceStore();
        $store->recordProvenance($this->provenance());

        self::assertTrue($store->isConsentComplete('ds1'));
        self::assertFalse(
            new InMemoryDataGovernanceStore()->isConsentComplete('ds1'),
            'so a dataset consented last week reads as unconsented today',
        );
    }

    // --- Explainability -------------------------------------------------------

    #[Test]
    public function theExplanationSurvivesWithTheFactorsThatMakeItAnExplanation(): void
    {
        new DbExplainabilityStore($this->connection)->record($this->explanation());

        $fresh = new DbExplainabilityStore($this->connection);
        $back = $fresh->explain('d1');

        self::assertNotNull($back);
        self::assertSame('The application was ranked below the threshold.', $back->summary);
        self::assertSame('m1', $back->modelId);
        self::assertSame(0.75, $back->confidence);
        self::assertSame(self::INSTANT, $back->generatedAt->getTimestamp());
        self::assertSame(['refer to a human reviewer'], $back->alternativesConsidered);

        self::assertCount(1, $back->factors);
        self::assertSame('years_of_experience', $back->factors[0]->name);
        self::assertSame(0.6, $back->factors[0]->weight);
        self::assertSame('Weighted most heavily of the four inputs.', $back->factors[0]->description);

        self::assertCount(1, $fresh->getByModel('m1'));
    }

    #[Test]
    public function theInMemoryExplainabilityStoreLosesTheExplanationBeforeAnybodyAsks(): void
    {
        $store = new InMemoryExplainabilityStore();
        $store->record($this->explanation());

        self::assertNotNull($store->explain('d1'));
        self::assertNull(
            new InMemoryExplainabilityStore()->explain('d1'),
            'and a data subject contesting a decision asks days later',
        );
    }

    // --- Monitoring records ---------------------------------------------------

    #[Test]
    public function monitoringResultsAreRetainedAsASequence(): void
    {
        $store = new DbMonitoringRecordStore($this->connection);
        $store->record($this->monitoringRecord('first', healthy: true, at: self::INSTANT));
        $store->record($this->monitoringRecord('second', healthy: false, at: self::INSTANT + 86_400));

        $fresh = new DbMonitoringRecordStore($this->connection);

        self::assertSame(2, $fresh->countForModel('m1'), 'a second run is a second piece of evidence');

        $records = $fresh->forModel('m1');

        self::assertCount(2, $records);
        self::assertSame('second', $records[0]->message, 'newest first');
        self::assertFalse($records[0]->healthy);
        self::assertSame(['breaches' => 1], $records[0]->metrics);
        self::assertSame(self::INSTANT + 86_400, $records[0]->observedAt->getTimestamp());
    }

    #[Test]
    public function forModelHonoursItsLimit(): void
    {
        $store = new DbMonitoringRecordStore($this->connection);
        $store->record($this->monitoringRecord('first', healthy: true, at: self::INSTANT));
        $store->record($this->monitoringRecord('second', healthy: true, at: self::INSTANT + 86_400));

        self::assertCount(1, new DbMonitoringRecordStore($this->connection)->forModel('m1', 1));
    }

    #[Test]
    public function disposalIsScopedToOneModelAndReportsWhatItRemoved(): void
    {
        $store = new DbMonitoringRecordStore($this->connection);
        $store->record($this->monitoringRecord('kept', healthy: true, at: self::INSTANT, modelId: 'm2'));
        $store->record($this->monitoringRecord('purged', healthy: true, at: self::INSTANT));

        $fresh = new DbMonitoringRecordStore($this->connection);

        self::assertSame(1, $fresh->purgeForModel('m1'));
        self::assertSame(0, $fresh->countForModel('m1'));
        self::assertSame(1, $fresh->countForModel('m2'), 'disposal cannot empty the register');
    }

    #[Test]
    public function theInMemoryMonitoringStoreRetainsNothingAcrossInstances(): void
    {
        $store = new InMemoryMonitoringRecordStore();
        $store->record($this->monitoringRecord('first', healthy: true, at: self::INSTANT));

        self::assertSame(1, $store->countForModel('m1'));
        self::assertSame(
            0,
            new InMemoryMonitoringRecordStore()->countForModel('m1'),
            'Clause 9.1 asks for retained evidence of the results, and this retains none',
        );
    }

    // --- The migration --------------------------------------------------------

    #[Test]
    public function theShippedMigrationCreatesEverySchemaTheStoresRead(): void
    {
        // sqlite_* names are SQLite's own (AUTOINCREMENT adds sqlite_sequence), not the migration's.
        $tables = $this->connection->query(
            "SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name",
        )->pluck('name');

        self::assertSame([
            'ai_data_provenance',
            'ai_data_quality_reports',
            'ai_explanations',
            'ai_impact_assessments',
            'ai_impact_findings',
            'ai_models',
            'ai_monitoring_records',
            'ai_oversight_assignments',
            'ai_oversight_interventions',
            'ai_transparency_policies',
        ], $tables);
    }

    #[Test]
    public function theShippedMigrationIsReversible(): void
    {
        AiGovernanceSchema::drop($this->connection);

        self::assertSame([], $this->connection->query(
            "SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'",
        )->pluck('name'));
    }

    #[Test]
    public function applyingTheMigrationTwiceIsHarmless(): void
    {
        // Migrations run against databases that may already carry part of the
        // schema; `IF NOT EXISTS` on every statement is what makes that true, and
        // asserting it here means a statement that loses the guard is caught.
        AiGovernanceSchema::apply($this->connection);

        new DbModelRegistry($this->connection)->register($this->model());

        self::assertNotNull($this->freshRegistry()->get('m1'));
    }

    // --- Fixtures -------------------------------------------------------------

    private function freshRegistry(): DbModelRegistry
    {
        return new DbModelRegistry($this->connection);
    }

    private function model(
        ?ModelCard $card = null,
        string $id = 'm1',
        string $name = 'Model One',
        AiModelRiskLevel $risk = AiModelRiskLevel::High,
        AiModelStatus $status = AiModelStatus::Staging,
    ): AiModel {
        return new AiModel(
            id: $id,
            name: $name,
            version: '1.2.3',
            provider: 'acme',
            type: 'llm',
            riskLevel: $risk,
            status: $status,
            registeredAt: $this->instant(self::INSTANT),
            card: $card,
        );
    }

    private function card(): ModelCard
    {
        return new ModelCard(
            description: 'What the model does',
            intendedUse: 'Who may use it and for what',
            capabilities: ['ranks applications'],
            limitations: ['English only'],
            knownBiases: ['under-represents applicants over 60'],
            trainingDataSources: ['internal HR archive'],
            performanceMetrics: ['auc' => 0.91],
            ethicalConsiderations: ['human review is mandatory'],
        );
    }

    private function finding(string $id, ImpactSeverity $severity): ImpactFinding
    {
        return new ImpactFinding(
            id: $id,
            category: ImpactCategory::Fairness,
            severity: $severity,
            title: 'Age skew in the training archive',
            description: 'Applicants over 60 are under-represented.',
            recommendation: 'Rebalance the archive before the next training run.',
        );
    }

    private function provenance(string $id = 'p1', bool $consent = true): DataProvenance
    {
        return new DataProvenance(
            id: $id,
            datasetId: 'ds1',
            source: 'vendor://acme/hr-archive',
            dataType: 'structured',
            collectedAt: $this->instant(self::INSTANT),
            consentObtained: $consent,
            consentReference: $consent ? 'consent-2026-01' : null,
            license: 'MIT',
            transformations: ['deduplicated'],
            qualityMetrics: ['rows' => 1000],
        );
    }

    private function qualityReport(): DataQualityReport
    {
        return new DataQualityReport(
            datasetId: 'ds1',
            assessedAt: $this->instant(self::INSTANT),
            completeness: 99.5,
            accuracy: 98.25,
            consistency: 97.0,
            totalRecords: 1000,
            invalidRecords: 7,
            issues: ['12 rows missing a locale'],
        );
    }

    private function explanation(): Explanation
    {
        return new Explanation(
            decisionId: 'd1',
            modelId: 'm1',
            summary: 'The application was ranked below the threshold.',
            factors: [new DecisionFactor(
                'years_of_experience',
                0.6,
                'Weighted most heavily of the four inputs.',
            )],
            confidence: 0.75,
            generatedAt: $this->instant(self::INSTANT),
            alternativesConsidered: ['refer to a human reviewer'],
        );
    }

    private function monitoringRecord(
        string $message,
        bool $healthy,
        int $at,
        string $modelId = 'm1',
    ): MonitoringRecord {
        return new MonitoringRecord(
            modelId: $modelId,
            hookName: 'governance_conformity',
            healthy: $healthy,
            message: $message,
            metrics: ['breaches' => $healthy ? 0 : 1],
            observedAt: $this->instant($at),
        );
    }

    private function instant(int $unixSeconds): DateTimeImmutable
    {
        return new DateTimeImmutable('@' . $unixSeconds)->setTimezone(new DateTimeZone('UTC'));
    }
}
