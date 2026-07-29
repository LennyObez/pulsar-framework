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
     * Load and build a typed config DTO from raw data, environment, and the
     * repository of already-built sections.
     *
     * Loaders run AFTER the framework's hardcoded sections, so `$repository`
     * already holds AppConfig, SecurityConfig, etc. A loader whose DTO depends on
     * another section (e.g. introspection's default-enabled derives from
     * AppConfig's resolved EnvironmentMode) reads it from there rather than
     * re-deriving it and risking divergence.
     *
     * @param array<string, mixed> $data
     */
    public function load(array $data, Environment $environment, ConfigRepository $repository): object;
}
