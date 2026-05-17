<?php

declare(strict_types=1);

namespace Pulsar\Config;

use Pulsar\Api\Api;
use Pulsar\Config\Exception\ConfigException;

/**
 * Public contract for configuration management.
 *
 * Provides access to the loaded ConfigRepository, Environment,
 * and the config directory path for extensions that need to
 * discover additional config files.
 * @api
 */
#[Api(since: '1.0.0')]
interface ConfigManagerInterface
{
    /**
     * Get the ConfigRepository instance.
     *
     * @throws ConfigException If configuration has not been loaded.
     */
    public function repository(): ConfigRepository;

    /**
     * Get the Environment instance.
     *
     * @throws ConfigException If configuration has not been loaded.
     */
    public function environment(): Environment;

    /**
     * Get the config directory path.
     */
    public function configPath(): ?string;
}
