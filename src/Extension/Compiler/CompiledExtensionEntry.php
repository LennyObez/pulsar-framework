<?php

declare(strict_types=1);

namespace Pulsar\Extension\Compiler;

use NoDiscard;
use Pulsar\Api\Api;

use function is_string;

/**
 * A single extension entry within a compiled extension manifest.
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
        /** @var list<string> $dependencies */
        $dependencies = $data['dependencies'] ?? [];

        return new self(
            name: is_string($data['name'] ?? null) ? $data['name'] : '',
            version: is_string($data['version'] ?? null) ? $data['version'] : '',
            extensionClass: is_string($data['extensionClass'] ?? null) ? $data['extensionClass'] : '',
            enabled: (bool) ($data['enabled'] ?? true),
            dependencies: $dependencies,
            trustTier: is_string($data['trustTier'] ?? null) ? $data['trustTier'] : 'community',
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
