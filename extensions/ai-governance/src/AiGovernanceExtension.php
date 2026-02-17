<?php

declare(strict_types=1);

namespace Pulsar\Extension\AiGovernance;

use Override;
use Pulsar\Api\Api;
use Pulsar\Container\ContainerInterface;
use Pulsar\Extensibility\ExtensionInterface;
use Pulsar\Extensibility\ServiceProviderInterface;
use Pulsar\Routing\RouterInterface;

/**
 * ISO 42001:2023 AI Management System governance extension.
 *
 * Provides a complete AI governance framework including model registry,
 * impact assessments, audit logging, data governance, transparency/explainability,
 * and lifecycle management with deployment gates and monitoring hooks.
 *
 * This is the first PHP framework to implement ISO 42001 AI governance controls.
 */
#[Api(since: '1.0.0')]
final class AiGovernanceExtension implements ExtensionInterface
{
    #[Override]
    public function name(): string
    {
        return 'pulsar/ai-governance';
    }

    #[Override]
    public function register(ContainerInterface $container): void
    {
        // Service provider handles all bindings
    }

    #[Override]
    public function boot(ContainerInterface $container, RouterInterface $router): void
    {
        // No routes: AI governance is a pure backend service extension
    }

    /**
     * @return list<class-string<ServiceProviderInterface>>
     */
    #[Override]
    public function providers(): array
    {
        return [
            AiGovernanceServiceProvider::class,
        ];
    }
}
