<?php

declare(strict_types=1);

namespace Pulsar\Container;

use Pulsar\Api\Api;

/**
 * Defines how a container binding should be resolved.
 *
 * Covers the two most common lifetimes (singleton and factory/transient).
 * For request-scoped or tenant-scoped lifetimes, use
 * {@see Lifetime} with {@see AdvancedContainerInterface::bindWithLifetime()}.
 */
#[Api(since: '1.0.0')]
enum BindingType: string
{
    /**
     * Singleton: The binding is resolved once and cached.
     * Subsequent resolutions return the same instance.
     */
    case Singleton = 'singleton';

    /**
     * Factory: A new instance is created on each resolution.
     */
    case Factory = 'factory';

    /**
     * Convert to the equivalent Lifetime value.
     */
    public function toLifetime(): Lifetime
    {
        return match ($this) {
            self::Singleton => Lifetime::Singleton,
            self::Factory => Lifetime::Transient,
        };
    }
}
