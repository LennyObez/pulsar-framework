<?php

declare(strict_types=1);

namespace Pulsar\Config;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Config\Exception\ConfigException;

/**
 * Typed configuration store.
 *
 * Stores configuration DTOs keyed by class name and retrieves them
 * with full type information.
 *
 */
#[Api]
final class ConfigRepository
{
    /**
     * @var array<class-string, object>
     */
    private array $configs = [];

    /**
     * Store a config DTO instance.
     */
    public function set(object $config): void
    {
        $this->configs[$config::class] = $config;
    }

    /**
     * Retrieve a config DTO by class name.
     *
     * @template C of object
     * @param class-string<C> $class
     * @return C
     *
     * @throws ConfigException If the config class has not been registered.
     */
    #[NoDiscard]
    public function get(string $class): object
    {
        if (!isset($this->configs[$class])) {
            throw ConfigException::missingRequired($class, 'config repository');
        }

        /** @var C */
        return $this->configs[$class];
    }

    /**
     * Check if a config DTO has been registered.
     *
     * @param class-string $class
     */
    public function has(string $class): bool
    {
        return isset($this->configs[$class]);
    }
}
