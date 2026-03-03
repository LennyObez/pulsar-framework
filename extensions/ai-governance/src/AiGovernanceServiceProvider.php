<?php

declare(strict_types=1);

namespace Pulsar\Extension\AiGovernance;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Container\ContainerInterface;
use Pulsar\Container\Resolution\TypedServiceResolver;
use Pulsar\Extensibility\ServiceProviderInterface;
use Pulsar\Extension\AiGovernance\Config\AiGovernanceConfig;
use Pulsar\Extension\AiGovernance\Contracts\AiAuditLoggerInterface;
use Pulsar\Extension\AiGovernance\Contracts\AiDataGovernanceInterface;
use Pulsar\Extension\AiGovernance\Contracts\AiImpactAssessmentInterface;
use Pulsar\Extension\AiGovernance\Contracts\AiLifecycleManagerInterface;
use Pulsar\Extension\AiGovernance\Contracts\AiModelRegistryInterface;
use Pulsar\Extension\AiGovernance\Contracts\ExplainabilityInterface;
use Pulsar\Extension\AiGovernance\Internal\AiAuditLogger;
use Pulsar\Extension\AiGovernance\Internal\AiLifecycleManager;
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
            /** @var array<string, mixed> $configData */
            $configData = [];

            if ($container->has('config.ai_governance')) {
                /** @var array<string, mixed> $configData */
                $configData = $container->get('config.ai_governance');
            }

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

        // Impact Assessment
        $container->bind(AiImpactAssessmentInterface::class, static function (): AiImpactAssessmentInterface {
            return new InMemoryImpactAssessmentStore();
        });

        // AI Audit Logger (delegates to core audit logger)
        $container->bind(AiAuditLoggerInterface::class, static function () use ($container): AiAuditLoggerInterface {
            /** @var AuditLoggerInterface $auditLogger */
            $auditLogger = $container->get(AuditLoggerInterface::class);

            return new AiAuditLogger($auditLogger);
        });

        // Data Governance
        $container->bind(AiDataGovernanceInterface::class, static function () use ($container): AiDataGovernanceInterface {
            /** @var AiGovernanceConfig $config */
            $config = $container->get(AiGovernanceConfig::class);

            if ($config->dataGovernanceStore === 'memory') {
                return new InMemoryDataGovernanceStore();
            }

            return TypedServiceResolver::resolve(
                $container,
                $config->dataGovernanceStore,
                AiDataGovernanceInterface::class,
                'ai_governance.data_governance_store',
            );
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

            return new AiLifecycleManager($registry, $auditLogger);
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
        ];
    }
}
