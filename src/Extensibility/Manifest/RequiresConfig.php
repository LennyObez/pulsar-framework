<?php

declare(strict_types=1);

namespace Pulsar\Extensibility\Manifest;

use NoDiscard;
use Pulsar\Api\Api;

use function array_keys;
use function is_string;

/**
 * Configuration for extension dependencies.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class RequiresConfig
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
     * Input arrives untyped from json_decode of pulsar.json; filter to a
     * string => string map before constructing the value object.
     *
     * @param array<string, mixed> $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        $extensions = [];
        foreach ($data as $name => $constraint) {
            if (is_string($name) && is_string($constraint)) {
                $extensions[$name] = $constraint;
            }
        }

        return new self(extensions: $extensions);
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
