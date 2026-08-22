<?php

declare(strict_types=1);

namespace Pulsar\Config;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Config\Exception\ConfigException;

use function array_values;

/**
 * Typed configuration store.
 *
 * Stores configuration DTOs keyed by class name and retrieves them
 * with full type information.
 *
 * @api
 */
#[Api(since: '1.0.0')]
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

    /**
     * Every registered config DTO, in registration order.
     *
     * Used by {@see ConfigManager} to sweep for {@see ReportsUnknownKeys}
     * implementors after loading, so unknown-key detection has one chokepoint
     * that covers framework and extension config alike.
     *
     * @return list<object>
     */
    #[NoDiscard]
    public function all(): array
    {
        return array_values($this->configs);
    }
}
