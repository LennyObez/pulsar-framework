<?php

declare(strict_types=1);

namespace Pulsar\Introspection\Data;

use Pulsar\Api\Api;

use function array_map;

/**
 * High-level architecture map: registered extensions and container bindings.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class ArchitectureMapData
{
    /**
     * @param list<ExtensionEntry> $extensions
     * @param list<string>         $bindings
     */
    public function __construct(
        public array $extensions = [],
        public array $bindings = [],
    ) {}

    /**
     * @return array{extensions: list<array{name: string, version: string, state: string, provides: list<string>, dependencies: list<string>}>, bindings: list<string>}
     */
    public function toArray(): array
    {
        return [
            'extensions' => array_map(
                static fn(ExtensionEntry $e): array => $e->toArray(),
                $this->extensions,
            ),
            'bindings' => $this->bindings,
        ];
    }
}
