<?php

declare(strict_types=1);

namespace Pulsar\Extensibility;

use Pulsar\Api\Api;
use Pulsar\Container\ContainerInterface;

/**
 * Optional lifecycle hook called after all extensions register but before boot.
 *
 * Extensions implementing this interface get a preBoot() call after the
 * register phase completes for ALL extensions. This guarantees that every
 * service registered by any extension is available for resolution.
 *
 * Phase ordering (all phases respect the dependency-resolved extension order):
 *   register → preBoot → boot → postBoot
 */
#[Api(since: '1.0.0')]
interface PreBootExtensionInterface
{
    /**
     * Called after all extensions have registered, before any boot() runs.
     */
    public function preBoot(ContainerInterface $container): void;
}
