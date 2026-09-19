<?php

declare(strict_types=1);

namespace Pulsar\Extension\AiGovernance;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Compliance\Evidence\AiGovernanceDrillInterface;
use Pulsar\Compliance\Evidence\AiTransparencyDrillInterface;
use Pulsar\Container\ContainerInterface;
use Pulsar\Container\Resolution\TypedServiceResolver;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Extensibility\ExtensionConfigRegistry;
use Pulsar\Extensibility\ServiceProviderInterface;
use Pulsar\Extension\AiGovernance\Config\AiGovernanceConfig;
use Pulsar\Extension\AiGovernance\Contracts\AiAuditLoggerInterface;
use Pulsar\Extension\AiGovernance\Contracts\AiDataGovernanceInterface;
use Pulsar\Extension\AiGovernance\Contracts\AiImpactAssessmentInterface;
use Pulsar\Extension\AiGovernance\Contracts\AiLifecycleManagerInterface;
use Pulsar\Extension\AiGovernance\Contracts\AiModelRegistryInterface;
use Pulsar\Extension\AiGovernance\Contracts\AiTransparencyInterface;
use Pulsar\Extension\AiGovernance\Contracts\ExplainabilityInterface;
use Pulsar\Extension\AiGovernance\Contracts\HumanOversightInterface;
use Pulsar\Extension\AiGovernance\Contracts\MonitoringHookInterface;
use Pulsar\Extension\AiGovernance\Contracts\MonitoringRecordStoreInterface;
use Pulsar\Extension\AiGovernance\Exception\AiGovernanceException;
use Pulsar\Extension\AiGovernance\Internal\AiAuditLogger;
use Pulsar\Extension\AiGovernance\Internal\AiLifecycleManager;
use Pulsar\Extension\AiGovernance\Internal\Compliance\AiGovernanceDrill;
use Pulsar\Extension\AiGovernance\Internal\Compliance\AiTransparencyDrill;
use Pulsar\Extension\AiGovernance\Internal\ConsentEnforcingDataGovernance;
use Pulsar\Extension\AiGovernance\Internal\Gate\HighRiskObligationsGate;
use Pulsar\Extension\AiGovernance\Internal\Gate\ImpactAssessmentGate;
use Pulsar\Extension\AiGovernance\Internal\Gate\ModelCardGate;
use Pulsar\Extension\AiGovernance\Internal\Gate\ProhibitedPracticeGate;
use Pulsar\Extension\AiGovernance\Internal\Monitoring\GovernanceConformityHook;
use Pulsar\Extension\AiGovernance\Internal\MonitoringHookRegistry;
use Pulsar\Extension\AiGovernance\Internal\Store\DbAiTransparency;
use Pulsar\Extension\AiGovernance\Internal\Store\DbDataGovernanceStore;
use Pulsar\Extension\AiGovernance\Internal\Store\DbExplainabilityStore;
use Pulsar\Extension\AiGovernance\Internal\Store\DbHumanOversight;
use Pulsar\Extension\AiGovernance\Internal\Store\DbImpactAssessmentStore;
use Pulsar\Extension\AiGovernance\Internal\Store\DbModelRegistry;
use Pulsar\Extension\AiGovernance\Internal\Store\DbMonitoringRecordStore;
use Pulsar\Extension\AiGovernance\Internal\Store\InMemoryAiTransparency;
use Pulsar\Extension\AiGovernance\Internal\Store\InMemoryDataGovernanceStore;
use Pulsar\Extension\AiGovernance\Internal\Store\InMemoryExplainabilityStore;
use Pulsar\Extension\AiGovernance\Internal\Store\InMemoryHumanOversight;
use Pulsar\Extension\AiGovernance\Internal\Store\InMemoryImpactAssessmentStore;
use Pulsar\Extension\AiGovernance\Internal\Store\InMemoryModelRegistry;
use Pulsar\Extension\AiGovernance\Internal\Store\InMemoryMonitoringRecordStore;

/**
 * Service provider for the AI governance extension.
 *
 * Binds all AI governance services to the container based on configuration.
 *
 * WHAT IS WIRED BY DEFAULT CHANGED IN rc.12. Every store key defaulted to
 * `memory`, so an operator who enabled this extension and configured nothing got
 * a model inventory, impact assessments, data provenance and explanations that
 * lived in one worker's memory — thirteen ISO 42001 controls resting on a record
 * that was gone at the next restart. The default is now the durable store, and a
 * `database` key with no connection bound FAILS rather than substituting memory:
 * a silent fallback is how a deployment comes to believe it retains a record it
 * does not. See {@see AiGovernanceConfig::DATABASE}.
 *
 * ONE MONITORING HOOK IS NOW REGISTERED BY DEFAULT, and that is the second change
 * an operator should read. `MonitoringHookInterface` had zero implementations
 * anywhere in the tree, so Clause 9.1 could not be satisfied by any deployment and
 * the Article 72(3) limb of {@see HighRiskObligationsGate} could never pass.
 * {@see GovernanceConformityHook} is a real one — it re-reads, on a model already
 * in service, the obligations that model was admitted under — and it is a floor
 * rather than a monitoring plan; its own docblock says what an integrator still
 * owes.
 */
#[Internal(reason: 'Service wiring; use public contracts for access')]
final class AiGovernanceServiceProvider implements ServiceProviderInterface
{
    #[Override]
    public function register(ContainerInterface $container): void
    {
        // Config
        $container->bind(AiGovernanceConfig::class, static function () use ($container): AiGovernanceConfig {
            $configData = $container->has(ExtensionConfigRegistry::class)
                ? $container->get(ExtensionConfigRegistry::class)->section('ai_governance')
                : [];

            return AiGovernanceConfig::fromArray($configData);
        });

        // Model Registry
        $container->bind(AiModelRegistryInterface::class, static function () use ($container): AiModelRegistryInterface {
            /** @var AiGovernanceConfig $config */
            $config = $container->get(AiGovernanceConfig::class);

            return match ($config->registryStore) {
                AiGovernanceConfig::MEMORY => new InMemoryModelRegistry(),
                AiGovernanceConfig::DATABASE => new DbModelRegistry(
                    self::connection($container, 'registry_store'),
                ),
                default => TypedServiceResolver::resolve(
                    $container,
                    $config->registryStore,
                    AiModelRegistryInterface::class,
                    'ai_governance.registry_store',
                ),
            };
        });

        // Article 50 transparency. Bound UNCONDITIONALLY — there is no key that
        // makes this contract absent — because the duty it carries has applied
        // since 2 August 2026 and a deployment does not get to discharge a live
        // obligation by never wiring the thing that expresses it.
        //
        // WHERE the declared position is kept is a different question, and until
        // now it was answered here for every deployment: `InMemoryAiTransparency`
        // with no alternative. Every one of the five stores above got a durable
        // branch for a duty the digital omnibus deferred to 2027 or 2028, and the
        // one obligation actually in force kept its register in the memory of a
        // single worker. `declare()` is public, `#[Api]`, and says nothing about
        // only being callable at boot, so a deployment that declares surfaces
        // from an operator console lost them at the next restart and the
        // compliance report then listed fewer surfaces than had been declared.
        //
        // The default is `database`, matching the other five. A deployment that
        // declares every surface in code on every boot should say `memory`: the
        // in-memory store's own argument — that a stale row claiming a surface
        // owes no disclosure is worse than no row — holds for exactly that shape,
        // and the report repeats the choice back rather than hiding it.
        $container->bind(AiTransparencyInterface::class, static function () use ($container): AiTransparencyInterface {
            /** @var AiGovernanceConfig $config */
            $config = $container->get(AiGovernanceConfig::class);

            return match ($config->transparencyStore) {
                AiGovernanceConfig::MEMORY => new InMemoryAiTransparency(),
                AiGovernanceConfig::DATABASE => new DbAiTransparency(
                    self::connection($container, 'transparency_store'),
                ),
                default => TypedServiceResolver::resolve(
                    $container,
                    $config->transparencyStore,
                    AiTransparencyInterface::class,
                    'ai_governance.transparency_store',
                ),
            };
        });

        // The compliance seam over that contract. The framework declares it and
        // cannot call AiTransparencyInterface itself — that contract belongs to
        // this optional package — so without this binding the assessor has nothing
        // to exercise and `ai_transparency_exercised` reports that nothing ran.
        //
        // Bound unconditionally, beside the contract it wraps and for the same
        // reason: a configuration key able to switch the seam off would let a
        // deployment make the Article 50 fact absent while the subsystem it
        // reports on is fully in service, which is a way of declining to be
        // measured rather than a way of configuring anything.
        $container->bind(
            AiTransparencyDrillInterface::class,
            static function () use ($container): AiTransparencyDrillInterface {
                /** @var AiTransparencyInterface $transparency */
                $transparency = $container->get(AiTransparencyInterface::class);

                return new AiTransparencyDrill($transparency);
            },
        );

        // Impact Assessment. It had no configuration key at all until rc.12 — the
        // in-memory store was hard-wired here — so a deployment could not point
        // Clause 6.1.2 at a durable record even by asking.
        $container->bind(
            AiImpactAssessmentInterface::class,
            static function () use ($container): AiImpactAssessmentInterface {
                /** @var AiGovernanceConfig $config */
                $config = $container->get(AiGovernanceConfig::class);

                return match ($config->impactAssessmentStore) {
                    AiGovernanceConfig::MEMORY => new InMemoryImpactAssessmentStore(),
                    AiGovernanceConfig::DATABASE => new DbImpactAssessmentStore(
                        self::connection($container, 'impact_assessment_store'),
                    ),
                    default => TypedServiceResolver::resolve(
                        $container,
                        $config->impactAssessmentStore,
                        AiImpactAssessmentInterface::class,
                        'ai_governance.impact_assessment_store',
                    ),
                };
            },
        );

        // AI Audit Logger (delegates to core audit logger)
        $container->bind(AiAuditLoggerInterface::class, static function () use ($container): AiAuditLoggerInterface {
            /** @var AuditLoggerInterface $auditLogger */
            $auditLogger = $container->get(AuditLoggerInterface::class);

            /** @var AiGovernanceConfig $config */
            $config = $container->get(AiGovernanceConfig::class);

            return new AiAuditLogger($auditLogger, $config->auditInvocations);
        });

        // Data Governance
        $container->bind(AiDataGovernanceInterface::class, static function () use ($container): AiDataGovernanceInterface {
            /** @var AiGovernanceConfig $config */
            $config = $container->get(AiGovernanceConfig::class);

            $store = match ($config->dataGovernanceStore) {
                AiGovernanceConfig::MEMORY => new InMemoryDataGovernanceStore(),
                AiGovernanceConfig::DATABASE => new DbDataGovernanceStore(
                    self::connection($container, 'data_governance_store'),
                ),
                default => TypedServiceResolver::resolve(
                    $container,
                    $config->dataGovernanceStore,
                    AiDataGovernanceInterface::class,
                    'ai_governance.data_governance_store',
                ),
            };

            // Enforce the consent requirement at the boundary so the config
            // toggle is a real guarantee rather than inert metadata.
            return $config->requireConsentForTrainingData
                ? new ConsentEnforcingDataGovernance($store)
                : $store;
        });

        // Explainability
        $container->bind(ExplainabilityInterface::class, static function () use ($container): ExplainabilityInterface {
            /** @var AiGovernanceConfig $config */
            $config = $container->get(AiGovernanceConfig::class);

            return match ($config->explainabilityStore) {
                AiGovernanceConfig::MEMORY => new InMemoryExplainabilityStore(),
                AiGovernanceConfig::DATABASE => new DbExplainabilityStore(
                    self::connection($container, 'explainability_store'),
                ),
                default => TypedServiceResolver::resolve(
                    $container,
                    $config->explainabilityStore,
                    ExplainabilityInterface::class,
                    'ai_governance.explainability_store',
                ),
            };
        });

        // EU AI Act Article 14 and Article 26(2): who oversees each system, what
        // they may do to it, and what they have done. Modelled nowhere at all
        // before rc.12, which meant the deployer half of the high-risk obligations
        // could not be enforced and was therefore not enforced — the high-risk
        // gate demanded the PROVIDER artefacts from every deployment instead.
        //
        // Bound unconditionally, like the five stores above it, because a
        // deployment cannot be a deployer of a high-risk system and hold no
        // oversight record; what it configures is where the record lives.
        $container->bind(HumanOversightInterface::class, static function () use ($container): HumanOversightInterface {
            /** @var AiGovernanceConfig $config */
            $config = $container->get(AiGovernanceConfig::class);

            return match ($config->oversightStore) {
                AiGovernanceConfig::MEMORY => new InMemoryHumanOversight(),
                AiGovernanceConfig::DATABASE => new DbHumanOversight(
                    self::connection($container, 'oversight_store'),
                ),
                default => TypedServiceResolver::resolve(
                    $container,
                    $config->oversightStore,
                    HumanOversightInterface::class,
                    'ai_governance.oversight_store',
                ),
            };
        });

        // Where monitoring results are retained. ISO 42001 Clause 9.1 ends by
        // requiring documented information as evidence of the results, and until
        // this binding existed monitor() ran the hooks and returned them to a
        // caller free to drop them.
        $container->bind(
            MonitoringRecordStoreInterface::class,
            static function () use ($container): MonitoringRecordStoreInterface {
                /** @var AiGovernanceConfig $config */
                $config = $container->get(AiGovernanceConfig::class);

                return match ($config->monitoringRecordStore) {
                    AiGovernanceConfig::MEMORY => new InMemoryMonitoringRecordStore(),
                    AiGovernanceConfig::DATABASE => new DbMonitoringRecordStore(
                        self::connection($container, 'monitoring_record_store'),
                    ),
                    default => TypedServiceResolver::resolve(
                        $container,
                        $config->monitoringRecordStore,
                        MonitoringRecordStoreInterface::class,
                        'ai_governance.monitoring_record_store',
                    ),
                };
            },
        );

        // The shipped hook, bound under the contract as well as registered with
        // the lifecycle manager. Registering it is what makes it RUN; binding it
        // is what makes `ai_monitoring_hook_resolved` answerable at all — the
        // compliance gatherer asks the container which class answers
        // MonitoringHookInterface, and before this binding the honest answer on
        // every deployment was "nothing", including deployments that had
        // registered hooks.
        $container->bind(MonitoringHookInterface::class, static function () use ($container): MonitoringHookInterface {
            /** @var AiImpactAssessmentInterface $assessments */
            $assessments = $container->get(AiImpactAssessmentInterface::class);

            /** @var AiGovernanceConfig $config */
            $config = $container->get(AiGovernanceConfig::class);

            return new GovernanceConformityHook($assessments, $config->impactRiskThreshold);
        });

        // Lifecycle Manager
        $container->bind(AiLifecycleManagerInterface::class, static function () use ($container): AiLifecycleManagerInterface {
            /** @var AiModelRegistryInterface $registry */
            $registry = $container->get(AiModelRegistryInterface::class);

            /** @var AiAuditLoggerInterface $auditLogger */
            $auditLogger = $container->get(AiAuditLoggerInterface::class);

            /** @var AiGovernanceConfig $config */
            $config = $container->get(AiGovernanceConfig::class);

            /** @var AiImpactAssessmentInterface $assessments */
            $assessments = $container->get(AiImpactAssessmentInterface::class);

            /** @var MonitoringRecordStoreInterface $monitoringRecords */
            $monitoringRecords = $container->get(MonitoringRecordStoreInterface::class);

            // Shared with the high-risk gate, which must be able to see whether
            // any monitoring hook exists before a high-risk system deploys.
            $monitoringHooks = new MonitoringHookRegistry();

            $manager = new AiLifecycleManager($registry, $auditLogger, $monitoringHooks, $monitoringRecords);

            /** @var MonitoringHookInterface $shippedHook */
            $shippedHook = $container->get(MonitoringHookInterface::class);

            // Registered before any gate is added, so the Article 72(3) limb of
            // the high-risk gate sees it. This is the change that makes a
            // high-risk deployment possible at all through this extension: the
            // gate has always required a registered hook and the tree shipped
            // none, so the obligation could be met only by an integrator writing
            // one. It still SHOULD be met that way as well — see the hook's own
            // docblock for what a governance-conformity check does not monitor.
            $manager->addMonitoringHook($shippedHook);

            // Risk classification decides deployment, and it decides it first.
            // Neither of these two gates sits behind a configuration key: an
            // Article 5 prohibition is not an operator preference, and the
            // pre-market obligations of a high-risk system do not lapse because
            // require_model_card ships off. They are registered ahead of the
            // configurable gates so a refusal names the regulation rather than a
            // house rule.
            /** @var HumanOversightInterface $oversight */
            $oversight = $container->get(HumanOversightInterface::class);

            $manager->addDeploymentGate(new ProhibitedPracticeGate());

            // The high-risk gate now needs three things it did not need before: the
            // oversight record, so the Article 26(2) deployer limb has something to
            // read, and the deployment's declared role, so it knows which limb to
            // enforce. Passing the role rather than letting the gate reach for
            // config keeps the precedence — this system's role, else the
            // deployment's — stated once, in AiModel::actorRoleOr().
            $manager->addDeploymentGate(new HighRiskObligationsGate(
                $assessments,
                $monitoringHooks,
                $oversight,
                $config->actorRole,
            ));

            // Translate the configured deployment requirements into gates the
            // lifecycle manager enforces on deploy(); without this the
            // require* toggles are inert.
            if ($config->requireModelCard) {
                $manager->addDeploymentGate(new ModelCardGate());
            }

            if ($config->requireImpactAssessment) {
                $manager->addDeploymentGate(new ImpactAssessmentGate($assessments, $config->impactRiskThreshold));
            }

            return $manager;
        });

        // The seam the compliance assessor exercises this extension's governance
        // record and its monitoring through. Bound beside the transparency drill
        // and for the same reason: the framework declares the port, this package
        // answers it, and a deployment without this extension binds nothing so
        // the facts report ABSENT rather than false.
        $container->bind(
            AiGovernanceDrillInterface::class,
            static function () use ($container): AiGovernanceDrillInterface {
                /** @var AiGovernanceConfig $config */
                $config = $container->get(AiGovernanceConfig::class);

                /** @var AiModelRegistryInterface $registry */
                $registry = $container->get(AiModelRegistryInterface::class);

                /** @var AiImpactAssessmentInterface $assessments */
                $assessments = $container->get(AiImpactAssessmentInterface::class);

                /** @var AiDataGovernanceInterface $dataGovernance */
                $dataGovernance = $container->get(AiDataGovernanceInterface::class);

                /** @var ExplainabilityInterface $explainability */
                $explainability = $container->get(ExplainabilityInterface::class);

                /** @var AiLifecycleManagerInterface $lifecycle */
                $lifecycle = $container->get(AiLifecycleManagerInterface::class);

                /** @var MonitoringRecordStoreInterface $monitoringRecords */
                $monitoringRecords = $container->get(MonitoringRecordStoreInterface::class);

                return new AiGovernanceDrill(
                    registry: $registry,
                    assessments: $assessments,
                    dataGovernance: $dataGovernance,
                    explainability: $explainability,
                    lifecycle: $lifecycle,
                    monitoringRecords: $monitoringRecords,
                    config: $config,
                    connection: $container->has(ConnectionInterface::class)
                        ? $container->get(ConnectionInterface::class)
                        : null,
                );
            },
        );
    }

    #[Override]
    public function provides(): array
    {
        return [
            AiGovernanceConfig::class,
            AiModelRegistryInterface::class,
            AiImpactAssessmentInterface::class,
            AiAuditLoggerInterface::class,
            AiDataGovernanceInterface::class,
            ExplainabilityInterface::class,
            AiLifecycleManagerInterface::class,
            AiTransparencyInterface::class,
            AiTransparencyDrillInterface::class,
            HumanOversightInterface::class,
            MonitoringHookInterface::class,
            MonitoringRecordStoreInterface::class,
            AiGovernanceDrillInterface::class,
        ];
    }

    /**
     * The deployment's own database connection, or a refusal naming the key that
     * asked for it.
     *
     * There is deliberately no fallback. A durable store that quietly became an
     * in-memory one when no connection was bound would leave an operator holding
     * a compliance report about a record that does not exist, and it is exactly
     * the substitution the ISO 42001 mapping was rebuilt to stop making.
     *
     * @throws AiGovernanceException when the configured durable store has nothing to write to
     */
    private static function connection(ContainerInterface $container, string $configKey): ConnectionInterface
    {
        if (! $container->has(ConnectionInterface::class)) {
            throw AiGovernanceException::durableStoreNeedsConnection($configKey);
        }

        /** @var ConnectionInterface $connection */
        $connection = $container->get(ConnectionInterface::class);

        return $connection;
    }
}
