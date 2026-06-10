<?php

declare(strict_types=1);

namespace Pulsar\I18n\Compiler;

use Pulsar\Api\Internal;
use Pulsar\Support\Coerce;

use function is_array;

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
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $index = $data['index'] ?? null;
        $fileHashes = $data['file_hashes'] ?? null;

        return new self(
            locales: Coerce::listOfString($data['locales'] ?? null),
            index: is_array($index) ? $index : [],
            fileHashes: is_array($fileHashes) ? $fileHashes : [],
            totalHash: Coerce::string($data['total_hash'] ?? null),
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
