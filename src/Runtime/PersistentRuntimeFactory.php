<?php

declare(strict_types=1);

namespace Pulsar\Runtime;

use Psr\Log\LoggerInterface;
use Pulsar\Api\Internal;
use Pulsar\Config\RuntimeConfig;
use Pulsar\Container\ContainerInterface;
use Pulsar\Core\KernelInterface;
use Pulsar\Runtime\Upgrade\UpgradeContext;

/**
 * Assembles PersistentRuntime with its internal dependencies.
 *
 * Pulls LeakDetector, RequestResetRegistry, and RequestSandbox from
 * the container (registered by Kernel::createRuntimeServices()) so
 * that consumers only depend on the factory interface.
 *
 * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
 */
#[Internal]
final readonly class PersistentRuntimeFactory implements PersistentRuntimeFactoryInterface
{
    public function __construct(
        private ContainerInterface $container,
    ) {}

    public function create(
        KernelInterface $kernel,
        RuntimeConfig $config,
        ?LoggerInterface $logger = null,
        ?RuntimeCollectorInterface $collector = null,
        ?UpgradeContext $upgradeContext = null,
    ): RuntimeInterface {
        // Use container-registered instances if available, fall back to defaults
        $registry = $this->container->has(RequestResetRegistry::class)
            ? $this->container->get(RequestResetRegistry::class)
            : new RequestResetRegistry();

        /** @var RequestResetRegistry $registry */

        $leakDetector = $this->container->has(LeakDetector::class)
            ? $this->container->get(LeakDetector::class)
            : new LeakDetector(logger: $logger);

        /** @var LeakDetector $leakDetector */

        $sandbox = new RequestSandbox(
            $kernel->container(),
            $registry,
            $leakDetector,
        );

        return new PersistentRuntime(
            kernel: $kernel,
            sandbox: $sandbox,
            config: $config,
            logger: $logger,
            collector: $collector,
            upgradeContext: $upgradeContext,
        );
    }
}
