<?php

declare(strict_types=1);

namespace Pulsar\Container\Provider;

use Pulsar\Api\Api;
use Pulsar\Extensibility\ServiceProviderInterface;

/**
 * A service provider whose registration is deferred until first access.
 *
 * The provider's `register()` method is not called during bootstrap. Instead,
 * when any of its `provides()` service IDs is first requested via `get()`,
 * the container triggers `register()` just-in-time.
 * @api
 */
#[Api(since: '1.0.0')]
interface DeferredServiceProviderInterface extends ServiceProviderInterface
{
    /**
     * Whether this provider should be deferred.
     *
     * Returning true indicates that `register()` should be delayed until
     * one of the service IDs from `provides()` is first resolved.
     */
    public function isDeferred(): bool;
}
