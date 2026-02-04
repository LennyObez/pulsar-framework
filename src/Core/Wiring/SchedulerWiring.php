<?php

declare(strict_types=1);

namespace Pulsar\Core\Wiring;

use Psr\Log\LoggerInterface;
use Pulsar\Api\Internal;
use Pulsar\Config\ConfigManager;
use Pulsar\Config\SchedulerConfig;
use Pulsar\Container\ContainerInterface;
use Pulsar\Context\RequestContextHolder;
use Pulsar\Http\Middleware\MiddlewarePipeline;
use Pulsar\Http\Middleware\MiddlewareRegistry;
use Pulsar\Observability\Metrics\MetricRegistry;
use Pulsar\Routing\Router;
use Pulsar\Scheduler\JobRegistry;
use Pulsar\Scheduler\Scheduler;
use Random\Randomizer;

#[Internal]
final readonly class SchedulerWiring implements ServiceWiringInterface
{
    public function wire(
        ContainerInterface $container,
        ConfigManager $configManager,
        MiddlewarePipeline $middleware,
        MiddlewareRegistry $middlewareRegistry,
        Router $router,
    ): void {
        $repository = $configManager->repository();

        if (!$repository->has(SchedulerConfig::class)) {
            return;
        }

        /** @var SchedulerConfig $schedulerConfig */
        $schedulerConfig = $repository->get(SchedulerConfig::class);
        $container->instance(SchedulerConfig::class, $schedulerConfig);

        if (!$schedulerConfig->enabled) {
            return;
        }

        $logger = $container->has(LoggerInterface::class)
            ? $container->get(LoggerInterface::class)
            : null;

        $metrics = $container->has(MetricRegistry::class)
            ? $container->get(MetricRegistry::class)
            : null;

        $registry = new JobRegistry();
        $container->instance(JobRegistry::class, $registry);

        $contextHolder = $container->has(RequestContextHolder::class)
            ? $container->get(RequestContextHolder::class)
            : null;

        /** @var Randomizer|null $schedulerRandomizer */
        $schedulerRandomizer = $container->has(Randomizer::class)
            ? $container->get(Randomizer::class)
            : null;

        /** @var LoggerInterface|null $logger */
        /** @var MetricRegistry|null $metrics */
        /** @var RequestContextHolder|null $contextHolder */
        $scheduler = new Scheduler($registry, $logger, $metrics, $contextHolder, $schedulerRandomizer);
        $container->instance(Scheduler::class, $scheduler);
    }
}
