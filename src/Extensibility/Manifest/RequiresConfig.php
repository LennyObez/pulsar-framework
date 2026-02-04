<?php

declare(strict_types=1);

namespace Pulsar\Extensibility\Manifest;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * Configuration for extension dependencies.
 */
#[Api(since: '1.0.0')]
readonly class RequiresConfig
{
    /**
     * @param array<string, string> $extensions Map of extension name to version constraint
     */
    public function __construct(
        public array $extensions = [],
    ) {}

    /**
     * Create from manifest array data.
     *
     * @param array<string, string> $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        return new self(extensions: $data);
    }

    /**
     * Check if this extension has any dependencies.
     */
    public function hasDependencies(): bool
    {
        return $this->extensions !== [];
    }

    /**
     * Get all required extension names.
     *
     * @return list<string>
     */
    public function getExtensionNames(): array
    {
        return array_keys($this->extensions);
    }

    /**
     * Get the version constraint for a specific extension.
     */
    public function getVersionConstraint(string $extensionName): ?string
    {
        return $this->extensions[$extensionName] ?? null;
    }

    /**
     * Check if a specific extension is required.
     */
    public function requires(string $extensionName): bool
    {
        return isset($this->extensions[$extensionName]);
    }
}
