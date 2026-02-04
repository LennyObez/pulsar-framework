<?php

declare(strict_types=1);

namespace Pulsar\Config;

use Pulsar\Api\Api;

/**
 * Extension point for custom configuration loading.
 *
 * Not consumed by core in 0.3.0. Establishes the pattern for extensions
 * that provide their own typed config DTOs.
 */
#[Api(since: '1.0.0')]
interface ConfigLoaderInterface
{
    /**
     * The config DTO class this loader produces.
     *
     * @return class-string
     */
    public function configClass(): string;

    /**
     * Load and build a typed config DTO from raw data and environment.
     *
     * @param array<string, mixed> $data
     */
    public function load(array $data, Environment $environment): object;
}
