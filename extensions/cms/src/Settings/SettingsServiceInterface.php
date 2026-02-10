<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Settings;

use Pulsar\Api\Api;

/**
 * Service interface for reading and writing site settings.
 *
 * Supports per-group, per-key, and per-locale access patterns
 * with typed value serialization.
 */
#[Api(since: '1.0.0')]
interface SettingsServiceInterface
{
    /**
     * Get a single setting value by group and key.
     */
    public function get(string $group, string $key, ?string $locale = null): mixed;

    /**
     * Set a setting value with audit trail.
     */
    public function set(
        string $group,
        string $key,
        mixed $value,
        ?string $locale = null,
        ?string $reason = null,
    ): void;

    /**
     * Get all settings within a group.
     *
     * @return array<string, mixed> Keyed by setting key
     */
    public function getGroup(string $group, ?string $locale = null): array;

    /**
     * Get all settings across all groups.
     *
     * @return array<string, array<string, mixed>> Keyed by group, then by key
     */
    public function getAll(?string $locale = null): array;
}
