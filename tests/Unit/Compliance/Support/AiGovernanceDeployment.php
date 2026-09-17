<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Compliance\Support;

use Override;
use Pulsar\Audit\NullAuditLogger;
use Pulsar\Compliance\Evidence\AiGovernanceDrillInterface;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Driver;
use Pulsar\Database\Migration\MigrationInterface;
use Pulsar\Database\PdoConnection;
use Pulsar\Extension\AiGovernance\Config\AiGovernanceConfig;
use Pulsar\Extension\AiGovernance\Contracts\AiModelRegistryInterface;
use Pulsar\Extension\AiGovernance\Dto\AiModel;
use Pulsar\Extension\AiGovernance\Enum\AiModelRiskLevel;
use Pulsar\Extension\AiGovernance\Enum\AiModelStatus;
use Pulsar\Extension\AiGovernance\Exception\AiGovernanceException;
use Pulsar\Extension\AiGovernance\Internal\AiAuditLogger;
use Pulsar\Extension\AiGovernance\Internal\AiLifecycleManager;
use Pulsar\Extension\AiGovernance\Internal\Compliance\AiGovernanceDrill;
use Pulsar\Extension\AiGovernance\Internal\Monitoring\GovernanceConformityHook;
use Pulsar\Extension\AiGovernance\Internal\MonitoringHookRegistry;
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
use RuntimeException;

use function dirname;
use function glob;
use function is_object;
use function sort;

use const DIRECTORY_SEPARATOR;

/**
 * The AI governance seam, built the way real deployments are wired.
 *
 * Three shapes, and each is a deployment somebody could ship rather than a
 * hand-written fake:
 *
 *  - {@see durable()} is the shipped default since rc.12 — the `database` stores
 *    over the deployment's own connection, carrying the schema the shipped
 *    migration creates.
 *  - {@see inMemory()} is the shipped development stores, which is what every
 *    deployment got by NOT choosing until rc.12, and which the framework's own
 *    documentation calls evidence of nothing after a restart.
 *  - {@see thirdParty()} is a store configured by class name, which this
 *    framework cannot build a second instance of.
 *
 * There is one decorator, {@see refusingRegistry()}, and it breaks exactly one
 * thing: a registry that refuses the write. That is a bound-but-unusable
 * deployment — the shape ADR-0041 recorded for the token vault, where every
 * binding reads clean and the subsystem throws on first use — and it has to be
 * measurable as a subject that ran and failed rather than as a run that could
 * not happen.
 */
final readonly class AiGovernanceDeployment
{
    /**
     * All static; there is nothing to hold.
     */
    private function __construct() {}

    /**
     * The shipped default: durable stores over a real connection.
     */
    public static function durable(): AiGovernanceDrillInterface
    {
        $connection = self::connection();

        $registry = new DbModelRegistry($connection);
        $assessments = new DbImpactAssessmentStore($connection);
        $records = new DbMonitoringRecordStore($connection);

        return new AiGovernanceDrill(
            registry: $registry,
            assessments: $assessments,
            dataGovernance: new DbDataGovernanceStore($connection),
            explainability: new DbExplainabilityStore($connection),
            lifecycle: self::lifecycle($registry, $assessments, $records),
            monitoringRecords: $records,
            config: new AiGovernanceConfig(),
            connection: $connection,
        );
    }

    /**
     * The development stores, which is what the extension used to wire by default.
     */
    public static function inMemory(): AiGovernanceDrillInterface
    {
        $registry = new InMemoryModelRegistry();
        $assessments = new InMemoryImpactAssessmentStore();
        $records = new InMemoryMonitoringRecordStore();

        return new AiGovernanceDrill(
            registry: $registry,
            assessments: $assessments,
            dataGovernance: new InMemoryDataGovernanceStore(),
            explainability: new InMemoryExplainabilityStore(),
            lifecycle: self::lifecycle($registry, $assessments, $records),
            monitoringRecords: $records,
            config: new AiGovernanceConfig(
                registryStore: AiGovernanceConfig::MEMORY,
                impactAssessmentStore: AiGovernanceConfig::MEMORY,
                dataGovernanceStore: AiGovernanceConfig::MEMORY,
                explainabilityStore: AiGovernanceConfig::MEMORY,
                monitoringRecordStore: AiGovernanceConfig::MEMORY,
            ),
        );
    }

    /**
     * A store an operator configured by class name.
     *
     * The stores underneath are durable; what makes the check fail is that
     * nothing here knows how to construct a second instance of the configured
     * class, so the read would be served by the writer. That is honest and it is
     * a gap: the durability may well be real, and it was not established here.
     */
    public static function thirdParty(): AiGovernanceDrillInterface
    {
        $connection = self::connection();
        $store = 'Acme\\Governance\\ModelRegistry';

        $registry = new DbModelRegistry($connection);
        $assessments = new DbImpactAssessmentStore($connection);
        $records = new DbMonitoringRecordStore($connection);

        return new AiGovernanceDrill(
            registry: $registry,
            assessments: $assessments,
            dataGovernance: new DbDataGovernanceStore($connection),
            explainability: new DbExplainabilityStore($connection),
            lifecycle: self::lifecycle($registry, $assessments, $records),
            monitoringRecords: $records,
            config: new AiGovernanceConfig(
                registryStore: $store,
                impactAssessmentStore: $store,
                dataGovernanceStore: $store,
                explainabilityStore: $store,
                monitoringRecordStore: $store,
            ),
            connection: $connection,
        );
    }

    /**
     * A registry that is bound, resolves cleanly, and throws on first write.
     */
    public static function refusingRegistry(): AiGovernanceDrillInterface
    {
        $connection = self::connection();

        $registry = new class implements AiModelRegistryInterface {
            #[Override]
            public function register(AiModel $model): void
            {
                throw AiGovernanceException::modelNotFound($model->id);
            }

            #[Override]
            public function get(string $modelId): ?AiModel
            {
                return null;
            }

            #[Override]
            public function transitionStatus(string $modelId, AiModelStatus $newStatus): AiModel
            {
                throw AiGovernanceException::modelNotFound($modelId);
            }

            #[Override]
            public function updateRiskLevel(string $modelId, AiModelRiskLevel $riskLevel): AiModel
            {
                throw AiGovernanceException::modelNotFound($modelId);
            }

            #[Override]
            public function all(): array
            {
                return [];
            }

            #[Override]
            public function byStatus(AiModelStatus $status): array
            {
                return [];
            }

            #[Override]
            public function byRiskLevel(AiModelRiskLevel $riskLevel): array
            {
                return [];
            }
        };

        $assessments = new DbImpactAssessmentStore($connection);
        $records = new DbMonitoringRecordStore($connection);

        return new AiGovernanceDrill(
            registry: $registry,
            assessments: $assessments,
            dataGovernance: new DbDataGovernanceStore($connection),
            explainability: new DbExplainabilityStore($connection),
            lifecycle: self::lifecycle($registry, $assessments, $records),
            monitoringRecords: $records,
            config: new AiGovernanceConfig(),
            connection: $connection,
        );
    }

    /**
     * A deployment whose stores retain but which has registered no monitoring hook.
     *
     * The tree's position until rc.12, for every deployment: `MonitoringHookInterface`
     * had no implementation, so `monitor()` ran nothing and Clause 9.1 had no
     * result to retain.
     */
    public static function durableWithNoMonitoringHook(): AiGovernanceDrillInterface
    {
        $connection = self::connection();

        $registry = new DbModelRegistry($connection);
        $assessments = new DbImpactAssessmentStore($connection);
        $records = new DbMonitoringRecordStore($connection);

        return new AiGovernanceDrill(
            registry: $registry,
            assessments: $assessments,
            dataGovernance: new DbDataGovernanceStore($connection),
            explainability: new DbExplainabilityStore($connection),
            lifecycle: new AiLifecycleManager(
                $registry,
                new AiAuditLogger(new NullAuditLogger(), false),
                new MonitoringHookRegistry(),
                $records,
            ),
            monitoringRecords: $records,
            config: new AiGovernanceConfig(),
            connection: $connection,
        );
    }

    /**
     * A deployment that monitors and does not retain: the hooks run and the
     * results go nowhere.
     *
     * The half of Clause 9.1 that running a hook does not reach — *the
     * organization shall retain appropriate documented information as evidence of
     * the results*.
     */
    public static function monitoringWithoutRetention(): AiGovernanceDrillInterface
    {
        $connection = self::connection();

        $registry = new DbModelRegistry($connection);
        $assessments = new DbImpactAssessmentStore($connection);

        return new AiGovernanceDrill(
            registry: $registry,
            assessments: $assessments,
            dataGovernance: new DbDataGovernanceStore($connection),
            explainability: new DbExplainabilityStore($connection),
            lifecycle: self::lifecycle($registry, $assessments, new InMemoryMonitoringRecordStore()),
            monitoringRecords: new InMemoryMonitoringRecordStore(),
            config: new AiGovernanceConfig(monitoringRecordStore: AiGovernanceConfig::MEMORY),
            connection: $connection,
        );
    }

    private static function lifecycle(
        AiModelRegistryInterface $registry,
        DbImpactAssessmentStore|InMemoryImpactAssessmentStore $assessments,
        DbMonitoringRecordStore|InMemoryMonitoringRecordStore $records,
    ): AiLifecycleManager {
        $hooks = new MonitoringHookRegistry();
        $hooks->add(new GovernanceConformityHook($assessments, 7.0));

        return new AiLifecycleManager(
            $registry,
            new AiAuditLogger(new NullAuditLogger(), false),
            $hooks,
            $records,
        );
    }

    /**
     * A connection carrying the schema the shipped migrations create.
     *
     * The DDL is not duplicated here: a fixture that wrote its own CREATE TABLE
     * would pass against a schema the migration never produces, which is exactly
     * the shape ADR-0041 recorded.
     *
     * EVERY MIGRATION THE EXTENSION SHIPS IS RUN, discovered rather than named.
     * This used to name one file, and the second migration the extension shipped
     * — the one adding `ai_models.actor_role` and the Article 14 oversight tables
     * — was therefore invisible to this fixture: the durable stores wrote columns
     * the fixture's schema did not have, and the observers reported the AI
     * governance record as bound-but-unusable on a deployment where it works.
     * A named file is a second copy of the migration list, and it went stale the
     * first time the list grew.
     *
     * A file under `src/Migration` that does not return a MigrationInterface now
     * RAISES rather than being skipped. Skipping it silently is how a fixture
     * comes to pass against a schema no deployment ever has.
     */
    private static function connection(): ConnectionInterface
    {
        $connection = new PdoConnection(
            connectionName: 'ai-governance-assessment',
            driver: Driver::SQLite,
            dsn: 'sqlite::memory:',
            username: null,
            password: null,
        );

        $directory = dirname(__DIR__, 4)
            . DIRECTORY_SEPARATOR . 'extensions'
            . DIRECTORY_SEPARATOR . 'ai-governance'
            . DIRECTORY_SEPARATOR . 'src'
            . DIRECTORY_SEPARATOR . 'Migration';

        $paths = glob($directory . DIRECTORY_SEPARATOR . '2*.php');

        if ($paths === false || $paths === []) {
            throw new RuntimeException('No AI governance migrations were found under: ' . $directory);
        }

        // The runner orders by the timestamp prefix, and so does this: the second
        // migration alters a table the first one creates.
        sort($paths);

        foreach ($paths as $path) {
            /** @var mixed $migration */
            $migration = require $path;

            if (! is_object($migration) || ! $migration instanceof MigrationInterface) {
                throw new RuntimeException(
                    'An AI governance migration did not return a MigrationInterface: ' . $path,
                );
            }

            $migration->up($connection);
        }

        return $connection;
    }
}
