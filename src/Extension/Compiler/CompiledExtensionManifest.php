<?php

declare(strict_types=1);

namespace Pulsar\Extension\Compiler;

use NoDiscard;
use Pulsar\Api\Api;

use function array_map;
use function hash;
use function implode;
use function is_string;
use function ksort;
use function serialize;

use const SORT_STRING;

/**
 * Compiled extension manifest with dependency-ordered entries and content hashes.
 *
 * Produced by ExtensionGraphCompiler at build time and loaded from a cached PHP file
 * at boot time via ManifestLoader.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class CompiledExtensionManifest
{
    /**
     * @param list<CompiledExtensionEntry> $extensions Ordered by dependency resolution
     * @param array<string, string> $configHashes Extension name to config SHA-256
     * @param array<string, string> $codeHashes Extension name to code SHA-256
     * @param string $totalHash SHA-256 of the entire manifest for quick staleness check
     */
    public function __construct(
        public array $extensions,
        public array $configHashes,
        public array $codeHashes,
        public string $totalHash,
    ) {}

    /**
     * Create from array data.
     *
     * @param array<string, mixed> $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        /** @var list<array<string, mixed>> $extensionData */
        $extensionData = $data['extensions'] ?? [];

        $extensions = array_map(
            static fn(array $entry): CompiledExtensionEntry => CompiledExtensionEntry::fromArray($entry),
            $extensionData,
        );

        /** @var array<string, string> $configHashes */
        $configHashes = $data['configHashes'] ?? [];

        /** @var array<string, string> $codeHashes */
        $codeHashes = $data['codeHashes'] ?? [];

        return new self(
            extensions: $extensions,
            configHashes: $configHashes,
            codeHashes: $codeHashes,
            totalHash: is_string($data['totalHash'] ?? null) ? $data['totalHash'] : '',
        );
    }

    /**
     * Export to array representation.
     *
     * @return array{extensions: list<array{name: string, version: string, extensionClass: string, enabled: bool, dependencies: list<string>, trustTier: string}>, configHashes: array<string, string>, codeHashes: array<string, string>, totalHash: string}
     */
    public function toArray(): array
    {
        return [
            'extensions' => array_map(
                static fn(CompiledExtensionEntry $entry): array => $entry->toArray(),
                $this->extensions,
            ),
            'configHashes' => $this->configHashes,
            'codeHashes' => $this->codeHashes,
            'totalHash' => $this->totalHash,
        ];
    }

    /**
     * Compute total hash from config and code hashes.
     *
     * @param array<string, string> $configHashes
     * @param array<string, string> $codeHashes
     * @param list<CompiledExtensionEntry> $extensions
     */
    public static function computeTotalHash(
        array $configHashes,
        array $codeHashes,
        array $extensions,
    ): string {
        ksort($configHashes, SORT_STRING);
        ksort($codeHashes, SORT_STRING);

        $parts = [];

        foreach ($extensions as $entry) {
            $parts[] = $entry->name . ':' . $entry->version . ':' . ($entry->enabled ? '1' : '0');
        }

        $parts[] = serialize($configHashes);
        $parts[] = serialize($codeHashes);

        return hash('sha256', implode('|', $parts));
    }
}
