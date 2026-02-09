<?php

declare(strict_types=1);

namespace Pulsar\Runtime;

use Psr\Log\LoggerInterface;
use Pulsar\Api\Api;
use Pulsar\Config\RuntimeConfig;
use Pulsar\Core\KernelInterface;
use Pulsar\Runtime\Upgrade\UpgradeContext;

/**
 * Factory for creating persistent runtime instances.
 *
 * Encapsulates the assembly of runtime dependencies (sandbox, leak detector,
 * reset registry) so that console commands don't import internal Runtime types.
 */
#[Api(since: '1.0.0')]
interface PersistentRuntimeFactoryInterface
{
    /**
     * Create a configured persistent runtime ready to start.
     */
    public function create(
        KernelInterface $kernel,
        RuntimeConfig $config,
        ?LoggerInterface $logger = null,
        ?RuntimeCollectorInterface $collector = null,
        ?UpgradeContext $upgradeContext = null,
    ): RuntimeInterface;
}
