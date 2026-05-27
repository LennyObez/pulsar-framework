<?php

declare(strict_types=1);

namespace Pulsar\Extension\Compiler;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Support\Coerce;

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
     * @param array<string, mixed> $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        return new self(
            name: Coerce::string($data['name'] ?? null),
            version: Coerce::string($data['version'] ?? null),
            extensionClass: Coerce::string($data['extensionClass'] ?? null),
            enabled: Coerce::bool($data['enabled'] ?? null, true),
            dependencies: Coerce::listOfString($data['dependencies'] ?? null),
            trustTier: Coerce::string($data['trustTier'] ?? null, 'community'),
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
