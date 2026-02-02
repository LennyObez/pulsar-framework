<?php

declare(strict_types=1);

namespace Pulsar\Container;

/**
 * Defines how a container binding should be resolved.
 */
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
}
