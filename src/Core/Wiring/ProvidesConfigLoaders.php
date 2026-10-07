<?php

declare(strict_types=1);

namespace Pulsar\Core\Wiring;

use Pulsar\Api\Internal;
use Pulsar\Config\ConfigLoaderInterface;

/**
 * A wiring that owns one or more config sections and knows how to build their
 * DTOs.
 *
 * The composition root registers these loaders with {@see \Pulsar\Config\ConfigManager}
 * BEFORE `load()` runs, so each DTO is built into the {@see \Pulsar\Config\ConfigRepository}
 * — the single source of truth — during config loading rather than being read
 * ad-hoc from the file inside `wire()`. The wiring then resolves its config back
 * from the repository, and unknown-key reporting is handled once, centrally, by
 * ConfigManager's post-load sweep.
 *
 * Boundary note: the wiring already imports its own DTO, so declaring the loader
 * here adds no new cross-module dependency; ConfigManager never imports the DTO.
 */
#[Internal]
interface ProvidesConfigLoaders
{
    /**
     * Config loaders this wiring provides, keyed by config basename
     * (e.g. `'edge'` for `config/edge.php`).
     *
     * @return array<string, ConfigLoaderInterface>
     */
    public function configLoaders(): array;
}
