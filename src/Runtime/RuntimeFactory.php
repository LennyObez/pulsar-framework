<?php

declare(strict_types=1);

namespace Pulsar\Runtime;

use Psr\Log\LoggerInterface;
use Pulsar\Api\Api;
use Pulsar\Config\RuntimeConfig;
use Pulsar\Container\ContainerInterface;
use Pulsar\Core\KernelInterface;
use Pulsar\Runtime\Bridge\WorkerInterface;
use Pulsar\Runtime\Exception\RuntimeException;
use Pulsar\Runtime\Hygiene\HygieneProfileInterface;
use Pulsar\Runtime\Upgrade\UpgradeContext;

/**
 * Creates runtime instances for any supported runtime type.
 *
 * Pulls internal dependencies (sandbox, leak detector, hygiene profile) from
 * the container so that consumers only depend on the factory, not on internal
 * runtime construction details.
 */
#[Api(since: '1.0.0-rc.11')]
final readonly class RuntimeFactory implements PersistentRuntimeFactoryInterface
{
    public function __construct(
        private ContainerInterface $container,
    ) {}

    /**
     * Create a runtime instance for the given type.
     */
    public function createForType(
        RuntimeType $type,
        KernelInterface $kernel,
        RuntimeConfig $config,
        ?LoggerInterface $logger = null,
        ?RuntimeCollectorInterface $collector = null,
        ?UpgradeContext $upgradeContext = null,
    ): RuntimeInterface {
        $sandbox = $this->buildSandbox($kernel);

        return match ($type) {
            RuntimeType::Fpm => new FpmRuntime(kernel: $kernel),
            RuntimeType::Persistent => new PersistentRuntime(
                kernel: $kernel,
                sandbox: $sandbox,
                config: $config,
                logger: $logger,
                collector: $collector,
                upgradeContext: $upgradeContext,
            ),
            RuntimeType::FrankenPhp => new FrankenPhpRuntime(
                kernel: $kernel,
                sandbox: $sandbox,
                config: $config,
                logger: $logger,
                collector: $collector,
            ),
            RuntimeType::RoadRunner => $this->createRoadRunner(
                $kernel,
                $sandbox,
                $config,
                $logger,
                $collector,
            ),
        };
    }

    /**
     * Backward-compatible create method (implements PersistentRuntimeFactoryInterface).
     */
    public function create(
        KernelInterface $kernel,
        RuntimeConfig $config,
        ?LoggerInterface $logger = null,
        ?RuntimeCollectorInterface $collector = null,
        ?UpgradeContext $upgradeContext = null,
    ): RuntimeInterface {
        return $this->createForType(
            RuntimeType::Persistent,
            $kernel,
            $config,
            $logger,
            $collector,
            $upgradeContext,
        );
    }

    private function buildSandbox(KernelInterface $kernel): RequestSandbox
    {
        $registry = $this->container->has(RequestResetRegistry::class)
            ? $this->container->get(RequestResetRegistry::class)
            : new RequestResetRegistry();

        /** @var RequestResetRegistry $registry */

        $leakDetector = $this->container->has(LeakDetector::class)
            ? $this->container->get(LeakDetector::class)
            : new LeakDetector();

        /** @var LeakDetector $leakDetector */

        $hygiene = $this->container->has(HygieneProfileInterface::class)
            ? $this->container->get(HygieneProfileInterface::class)
            : null;

        /** @var HygieneProfileInterface|null $hygiene */

        return new RequestSandbox(
            $kernel->container(),
            $registry,
            $leakDetector,
            $hygiene,
        );
    }

    private function createRoadRunner(
        KernelInterface $kernel,
        RequestSandbox $sandbox,
        RuntimeConfig $config,
        ?LoggerInterface $logger,
        ?RuntimeCollectorInterface $collector,
    ): RoadRunnerRuntime {
        if (!$this->container->has(WorkerInterface::class)) {
            throw RuntimeException::extensionMissing('roadrunner (WorkerInterface not registered)');
        }

        $worker = $this->container->get(WorkerInterface::class);

        /** @var WorkerInterface $worker */

        return new RoadRunnerRuntime(
            kernel: $kernel,
            sandbox: $sandbox,
            config: $config,
            worker: $worker,
            logger: $logger,
            collector: $collector,
        );
    }
}
