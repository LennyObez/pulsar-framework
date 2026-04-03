<?php

declare(strict_types=1);

namespace Pulsar\Extension\Compiler;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * A single extension entry within a compiled extension manifest.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class CompiledExtensionEntry
{
    /**
     * @param list<string> $dependencies
     */
    public function __construct(
        public string $name,
        public string $version,
        public string $extensionClass,
        public bool $enabled,
        public array $dependencies,
        public string $trustTier,
    ) {}

    /**
     * Create from array data.
     *
     * @param array{
     *     name?: string,
     *     version?: string,
     *     extensionClass?: string,
     *     enabled?: bool|int|string,
     *     dependencies?: list<string>,
     *     trustTier?: string,
     * } $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        return new self(
            name: $data['name'] ?? '',
            version: $data['version'] ?? '',
            extensionClass: $data['extensionClass'] ?? '',
            enabled: (bool) ($data['enabled'] ?? true),
            dependencies: $data['dependencies'] ?? [],
            trustTier: $data['trustTier'] ?? 'community',
        );
    }

    /**
     * Export to array representation.
     *
     * @return array{name: string, version: string, extensionClass: string, enabled: bool, dependencies: list<string>, trustTier: string}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'version' => $this->version,
            'extensionClass' => $this->extensionClass,
            'enabled' => $this->enabled,
            'dependencies' => $this->dependencies,
            'trustTier' => $this->trustTier,
        ];
    }
}
