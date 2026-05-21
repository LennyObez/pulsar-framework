<?php

declare(strict_types=1);

namespace Pulsar\I18n\Compiler;

use Pulsar\Api\Internal;

/**
 * Readonly DTO representing a compiled i18n catalog index.
 *
 * Maps locales to domains to translation keys for production use
 * without runtime filesystem scanning.
 */
#[Internal]
final readonly class CompiledCatalogIndex
{
    /**
     * @param list<string> $locales Available locale codes (sorted)
     * @param array<string, array<string, list<string>>> $index locale → domain → keys
     * @param array<string, string> $fileHashes Relative path → SHA-256 hash
     * @param string $totalHash SHA-256 of all file hashes combined
     */
    public function __construct(
        public array $locales,
        public array $index,
        public array $fileHashes,
        public string $totalHash,
    ) {}

    /**
     * @param array{
     *     locales?: list<string>,
     *     index?: array<string, array<string, list<string>>>,
     *     file_hashes?: array<string, string>,
     *     total_hash?: string,
     * } $data
     * @psalm-api Wired-up through DI container or attribute discovery; Psalm cannot trace the call site.
     */
    public static function fromArray(array $data): self
    {
        return new self(
            locales: $data['locales'] ?? [],
            index: $data['index'] ?? [],
            fileHashes: $data['file_hashes'] ?? [],
            totalHash: $data['total_hash'] ?? '',
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'locales' => $this->locales,
            'index' => $this->index,
            'file_hashes' => $this->fileHashes,
            'total_hash' => $this->totalHash,
        ];
    }
}
