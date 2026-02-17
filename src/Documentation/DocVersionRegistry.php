<?php

declare(strict_types=1);

namespace Pulsar\Documentation;

use Pulsar\Api\Api;

use function array_values;
use function count;
use function usort;
use function version_compare;

/**
 * Registry of available documentation versions.
 *
 * Manages which versions are available and which is the current/latest.
 * Provides version resolution and navigation support.
 */
#[Api(since: '1.0.0')]
final class DocVersionRegistry
{
    /** @var array<string, DocVersion> */
    private array $versions = [];

    private ?string $defaultVersion = null;

    /**
     * Register a documentation version.
     */
    public function register(DocVersion $version): void
    {
        $this->versions[$version->version] = $version;

        if ($version->isLatest) {
            $this->defaultVersion = $version->version;
        }
    }

    /**
     * Get a version by its identifier, or null if not found.
     */
    public function get(string $version): ?DocVersion
    {
        return $this->versions[$version] ?? null;
    }

    /**
     * Resolve a version identifier to the actual version, falling back to latest.
     */
    public function resolve(string $version): DocVersion
    {
        return $this->versions[$version] ?? $this->latest();
    }

    /**
     * Get the latest stable version.
     */
    public function latest(): DocVersion
    {
        if ($this->defaultVersion !== null && isset($this->versions[$this->defaultVersion])) {
            return $this->versions[$this->defaultVersion];
        }

        // Fall back to highest non-prerelease version
        $stable = [];

        foreach ($this->versions as $v) {
            if (!$v->isPrerelease) {
                $stable[] = $v;
            }
        }

        if ($stable === []) {
            $stable = array_values($this->versions);
        }

        usort($stable, static fn(DocVersion $a, DocVersion $b): int => version_compare($b->version, $a->version));

        return $stable[0];
    }

    /**
     * Get all registered versions, sorted newest first.
     *
     * @return list<DocVersion>
     */
    public function all(): array
    {
        $all = array_values($this->versions);
        usort($all, static fn(DocVersion $a, DocVersion $b): int => version_compare($b->version, $a->version));

        return $all;
    }

    /**
     * Check if a version exists.
     */
    public function has(string $version): bool
    {
        return isset($this->versions[$version]);
    }

    /**
     * Get the number of registered versions.
     */
    public function count(): int
    {
        return count($this->versions);
    }
}
