<?php

declare(strict_types=1);

namespace Pulsar\Config;

use Pulsar\Api\Internal;

/**
 * Runtime configuration override bag.
 *
 * Allows overriding raw config arrays before typed DTO construction.
 * Overrides are applied via `array_replace_recursive` per domain.
 */
#[Internal]
final class ConfigOverrides
{
    /**
     * @var array<string, array<string, mixed>>
     */
    private array $overrides = [];

    /**
     * Add override values for a config domain (e.g. 'app', 'observability').
     *
     * @param array<string, mixed> $values
     */
    public function add(string $domain, array $values): void
    {
        if (!isset($this->overrides[$domain])) {
            $this->overrides[$domain] = $values;
        } else {
            /** @var array<string, mixed> $merged */
            $merged = array_replace_recursive($this->overrides[$domain], $values);
            $this->overrides[$domain] = $merged;
        }
    }

    /**
     * Apply overrides to a raw config array for the given domain.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function apply(string $domain, array $data): array
    {
        if (!isset($this->overrides[$domain])) {
            return $data;
        }

        /** @var array<string, mixed> $merged */
        $merged = array_replace_recursive($data, $this->overrides[$domain]);

        return $merged;
    }

    /**
     * Check if overrides exist for a domain.
     */
    public function has(string $domain): bool
    {
        return isset($this->overrides[$domain]);
    }
}
