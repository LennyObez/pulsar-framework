<?php

declare(strict_types=1);

namespace Pulsar\ServiceDiscovery;

use NoDiscard;
use Override;
use Pulsar\Api\Api;

use function array_key_exists;
use function array_keys;
use function is_array;
use function is_string;

/**
 * Static (config-file based) implementation of the configuration center.
 *
 * Configuration is loaded from an array (typically from a config file)
 * and stored in memory. Suitable for single-instance deployments and
 * as the default backend.
 * @api
 */
#[Api(since: '1.0.0')]
final class StaticConfigCenter implements ConfigCenterInterface
{
    /** @var array<string, array<string, string>> namespace → (key → value) */
    private array $store;

    /**
     * @param array<string, array<string, string>> $initial
     */
    public function __construct(array $initial = [])
    {
        $this->store = $initial;
    }

    /**
     * @param array<string, mixed> $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        $normalized = [];

        foreach ($data as $namespace => $entries) {
            if (!is_array($entries)) {
                continue;
            }

            $normalized[$namespace] = [];

            foreach ($entries as $key => $value) {
                if (is_string($key) && is_string($value)) {
                    $normalized[$namespace][$key] = $value;
                }
            }
        }

        return new self($normalized);
    }

    #[Override]
    public function get(string $namespace, string $key): ?string
    {
        return $this->store[$namespace][$key] ?? null;
    }

    #[Override]
    public function set(string $namespace, string $key, string $value): void
    {
        $this->store[$namespace][$key] = $value;
    }

    #[Override]
    public function delete(string $namespace, string $key): bool
    {
        if (!isset($this->store[$namespace][$key])) {
            return false;
        }

        unset($this->store[$namespace][$key]);

        if ($this->store[$namespace] === []) {
            unset($this->store[$namespace]);
        }

        return true;
    }

    #[Override]
    public function all(string $namespace): array
    {
        return $this->store[$namespace] ?? [];
    }

    #[Override]
    public function has(string $namespace, string $key): bool
    {
        return array_key_exists($namespace, $this->store)
            && array_key_exists($key, $this->store[$namespace]);
    }

    #[Override]
    public function namespaces(): array
    {
        return array_keys($this->store);
    }
}
