<?php

declare(strict_types=1);

namespace Pulsar\Core\Wiring;

use Pulsar\Api\Internal;
use Pulsar\Config\ConfigManager;
use Pulsar\Config\FeatureFlagConfig;
use Pulsar\Container\ContainerInterface;
use Pulsar\FeatureFlag\FeatureFlagManager;
use Pulsar\FeatureFlag\FeatureFlagManagerInterface;
use Pulsar\FeatureFlag\FlagDefinition;
use Pulsar\FeatureFlag\FlagEvaluationLog;
use Pulsar\FeatureFlag\FlagEvaluationLogInterface;
use Pulsar\FeatureFlag\FlagStorageDriver;
use Pulsar\FeatureFlag\FlagStorageInterface;
use Pulsar\FeatureFlag\Storage\FileFlagStorage;
use Pulsar\FeatureFlag\Storage\InMemoryFlagStorage;
use Pulsar\Http\Middleware\MiddlewarePipeline;
use Pulsar\Http\Middleware\MiddlewareRegistry;
use Pulsar\Routing\Router;

#[Internal]
final readonly class FeatureFlagWiring implements ServiceWiringInterface
{
    public function wire(
        ContainerInterface $container,
        ConfigManager $configManager,
        MiddlewarePipeline $middleware,
        MiddlewareRegistry $middlewareRegistry,
        Router $router,
    ): void {
        $repository = $configManager->repository();

        if (!$repository->has(FeatureFlagConfig::class)) {
            return;
        }

        /** @var FeatureFlagConfig $flagConfig */
        $flagConfig = $repository->get(FeatureFlagConfig::class);
        $container->instance(FeatureFlagConfig::class, $flagConfig);

        if (!$flagConfig->enabled) {
            return;
        }

        // Storage
        $storage = match ($flagConfig->storage) {
            FlagStorageDriver::Memory => new InMemoryFlagStorage(),
            FlagStorageDriver::File => new FileFlagStorage($flagConfig->filePath),
        };

        // Load pre-configured flags
        foreach ($flagConfig->flags as $name => $data) {
            /** @var array<string, mixed> $data */
            $storage->set(FlagDefinition::fromArray($name, $data));
        }

        $container->instance(FlagStorageInterface::class, $storage);
        $container->instance($storage::class, $storage);

        // Evaluation log
        $evaluationLog = new FlagEvaluationLog();
        $container->instance(FlagEvaluationLog::class, $evaluationLog);
        $container->instance(FlagEvaluationLogInterface::class, $evaluationLog);

        // Manager
        $manager = new FeatureFlagManager($storage, $evaluationLog, $flagConfig->defaultState);
        $container->instance(FeatureFlagManager::class, $manager);
        $container->instance(FeatureFlagManagerInterface::class, $manager);
    }
}
