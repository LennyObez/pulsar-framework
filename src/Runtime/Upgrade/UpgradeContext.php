<?php

declare(strict_types=1);

namespace Pulsar\Runtime\Upgrade;

use Psr\Log\LoggerInterface;
use Pulsar\Api\Api;
use Pulsar\Config\RuntimeConfig;
use Pulsar\Observability\Metrics\MetricRegistry;

/**
 * Safe context for upgrade handlers: no container reference.
 *
 * Exposes only persistent services that are safe to use in long-lived
 * connections outside the request sandbox.
 */
#[Api(since: '1.0.0')]
final readonly class UpgradeContext
{
    public function __construct(
        public ?LoggerInterface $logger = null,
        public ?MetricRegistry $metrics = null,
        public ?RuntimeConfig $config = null,
    ) {}
}
