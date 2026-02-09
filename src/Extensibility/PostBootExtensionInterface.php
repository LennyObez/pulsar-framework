<?php

declare(strict_types=1);

namespace Pulsar\Extensibility;

use Pulsar\Api\Api;
use Pulsar\Container\ContainerInterface;

/**
 * Optional lifecycle hook called after ALL extensions have booted.
 *
 * Extensions implementing this interface get a postBoot() call after the
 * boot phase completes for ALL extensions. This is the right place to
 * decorate or wrap services that may have been modified during boot.
 *
 * Phase ordering (all phases respect the dependency-resolved extension order):
 *   register → preBoot → boot → postBoot
 */
#[Api(since: '1.0.0')]
interface PostBootExtensionInterface
{
    /**
     * Called after all extensions have booted.
     */
    public function postBoot(ContainerInterface $container): void;
}
