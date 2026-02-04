<?php

declare(strict_types=1);

namespace Pulsar\Core\Wiring;

use Psr\Log\LoggerInterface;
use Pulsar\Api\Internal;
use Pulsar\Config\ConfigManager;
use Pulsar\Config\SupervisorConfig;
use Pulsar\Container\ContainerInterface;
use Pulsar\Http\Middleware\MiddlewarePipeline;
use Pulsar\Http\Middleware\MiddlewareRegistry;
use Pulsar\Routing\Router;
use Pulsar\Security\Audit\AuditLogger;
use Pulsar\Supervisor\PreflightCheck\PreflightRunner;
use Pulsar\Supervisor\PreflightCheck\PreflightRunnerInterface;
use Pulsar\Supervisor\Supervisor;
use Pulsar\Supervisor\SupervisorInterface;

#[Internal]
final readonly class SupervisorWiring implements ServiceWiringInterface
{
    public function wire(
        ContainerInterface $container,
        ConfigManager $configManager,
        MiddlewarePipeline $middleware,
        MiddlewareRegistry $middlewareRegistry,
        Router $router,
    ): void {
        $repository = $configManager->repository();

        if (!$repository->has(SupervisorConfig::class)) {
            return;
        }

        /** @var SupervisorConfig $supervisorConfig */
        $supervisorConfig = $repository->get(SupervisorConfig::class);
        $container->instance(SupervisorConfig::class, $supervisorConfig);

        if (!$supervisorConfig->enabled) {
            return;
        }

        $logger = $container->has(LoggerInterface::class)
            ? $container->get(LoggerInterface::class)
            : null;

        $auditLogger = $container->has(AuditLogger::class)
            ? $container->get(AuditLogger::class)
            : null;

        /** @var LoggerInterface|null $logger */
        /** @var AuditLogger|null $auditLogger */
        $supervisor = new Supervisor(
            config: $supervisorConfig,
            logger: $logger,
            auditLogger: $auditLogger,
        );
        $container->instance(Supervisor::class, $supervisor);
        $container->instance(SupervisorInterface::class, $supervisor);

        // Preflight runner (extracted for direct injection)
        $preflightRunner = new PreflightRunner([]);
        $container->instance(PreflightRunner::class, $preflightRunner);
        $container->instance(PreflightRunnerInterface::class, $preflightRunner);
    }
}
