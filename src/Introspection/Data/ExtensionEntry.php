<?php

declare(strict_types=1);

namespace Pulsar\Introspection\Data;

use Pulsar\Api\Api;

/**
 * Describes a registered extension and its lifecycle state.
 */
#[Api(since: '1.0.0')]
final readonly class ExtensionEntry
{
    /**
     * @param list<string> $provides
     * @param list<string> $dependencies
     */
    public function __construct(
        public string $name,
        public string $version,
        public string $state,
        public array $provides = [],
        public array $dependencies = [],
    ) {}

    /**
     * @return array{name: string, version: string, state: string, provides: list<string>, dependencies: list<string>}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'version' => $this->version,
            'state' => $this->state,
            'provides' => $this->provides,
            'dependencies' => $this->dependencies,
        ];
    }
}
