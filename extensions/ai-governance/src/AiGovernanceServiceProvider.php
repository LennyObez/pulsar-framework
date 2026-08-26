<?php

declare(strict_types=1);

namespace Pulsar\Extension\AiGovernance;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Container\ContainerInterface;
use Pulsar\Container\Resolution\TypedServiceResolver;
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
use Pulsar\Extension\AiGovernance\Internal\AiAuditLogger;
use Pulsar\Extension\AiGovernance\Internal\AiLifecycleManager;
use Pulsar\Extension\AiGovernance\Internal\ConsentEnforcingDataGovernance;
use Pulsar\Extension\AiGovernance\Internal\Gate\HighRiskObligationsGate;
use Pulsar\Extension\AiGovernance\Internal\Gate\ImpactAssessmentGate;
use Pulsar\Extension\AiGovernance\Internal\Gate\ModelCardGate;
use Pulsar\Extension\AiGovernance\Internal\Gate\ProhibitedPracticeGate;
use Pulsar\Extension\AiGovernance\Internal\MonitoringHookRegistry;
use Pulsar\Extension\AiGovernance\Internal\Store\InMemoryAiTransparency;
use Pulsar\Extension\AiGovernance\Internal\Store\InMemoryDataGovernanceStore;
use Pulsar\Extension\AiGovernance\Internal\Store\InMemoryExplainabilityStore;
use Pulsar\Extension\AiGovernance\Internal\Store\InMemoryImpactAssessmentStore;
use Pulsar\Extension\AiGovernance\Internal\Store\InMemoryModelRegistry;

/**
 * Service provider for the AI governance extension.
 *
 * Binds all AI governance services to the container based on configuration.
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

            if ($config->registryStore === 'memory') {
                return new InMemoryModelRegistry();
            }

            return TypedServiceResolver::resolve(
                $container,
                $config->registryStore,
                AiModelRegistryInterface::class,
                'ai_governance.registry_store',
            );
        });

        // Article 50 transparency. Bound unconditionally and with no configuration
        // switch, because the duty it carries has applied since 2 August 2026 and
        // a deployment cannot turn it off. What a deployment chooses is what it
        // DECLARES through the contract; whether the contract exists is not a
        // choice, or an application would discharge Article 50 by never wiring it.
        $container->bind(AiTransparencyInterface::class, static function (): AiTransparencyInterface {
            return new InMemoryAiTransparency();
        });

        // Impact Assessment
        $container->bind(AiImpactAssessmentInterface::class, static function (): AiImpactAssessmentInterface {
            return new InMemoryImpactAssessmentStore();
        });

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

            $store = $config->dataGovernanceStore === 'memory'
                ? new InMemoryDataGovernanceStore()
                : TypedServiceResolver::resolve(
                    $container,
                    $config->dataGovernanceStore,
                    AiDataGovernanceInterface::class,
                    'ai_governance.data_governance_store',
                );

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

            if ($config->explainabilityStore === 'memory') {
                return new InMemoryExplainabilityStore();
            }

            return TypedServiceResolver::resolve(
                $container,
                $config->explainabilityStore,
                ExplainabilityInterface::class,
                'ai_governance.explainability_store',
            );
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

            // Shared with the high-risk gate, which must be able to see whether
            // any monitoring hook exists before a high-risk system deploys.
            $monitoringHooks = new MonitoringHookRegistry();

            $manager = new AiLifecycleManager($registry, $auditLogger, $monitoringHooks);

            // Risk classification decides deployment, and it decides it first.
            // Neither of these two gates sits behind a configuration key: an
            // Article 5 prohibition is not an operator preference, and the
            // pre-market obligations of a high-risk system do not lapse because
            // require_model_card ships off. They are registered ahead of the
            // configurable gates so a refusal names the regulation rather than a
            // house rule.
            $manager->addDeploymentGate(new ProhibitedPracticeGate());
            $manager->addDeploymentGate(new HighRiskObligationsGate($assessments, $monitoringHooks));

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
        ];
    }
}
