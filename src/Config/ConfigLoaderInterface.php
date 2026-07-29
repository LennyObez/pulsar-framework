<?php

declare(strict_types=1);

namespace Pulsar\Config;

use Pulsar\Api\Api;

/**
 * Extension point for typed configuration loading.
 *
 * A loader declares the DTO class it produces and builds that DTO from the
 * parsed `config/<basename>.php` array. {@see ConfigManager::load()} walks the
 * registered loaders and stores each result in the {@see ConfigRepository} — the
 * single source of truth — so a wiring (or extension) that owns a config section
 * never has to read the file ad-hoc. Core wirings register theirs via
 * {@see \Pulsar\Core\Wiring\ProvidesConfigLoaders}; extensions via
 * {@see ConfigManager::registerLoader()}.
 * @api
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
