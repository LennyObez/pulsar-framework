<?php

declare(strict_types=1);

namespace Pulsar\I18n\Compiler;

use Pulsar\Api\Internal;

use function is_array;
use function is_string;

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
        /** @var list<string> $locales */
        $locales = isset($data['locales']) && is_array($data['locales']) ? $data['locales'] : [];

        /** @var array<string, array<string, list<string>>> $index */
        $index = isset($data['index']) && is_array($data['index']) ? $data['index'] : [];

        /** @var array<string, string> $fileHashes */
        $fileHashes = isset($data['file_hashes']) && is_array($data['file_hashes']) ? $data['file_hashes'] : [];

        $totalHash = isset($data['total_hash']) && is_string($data['total_hash']) ? $data['total_hash'] : '';

        return new self(
            locales: $locales,
            index: $index,
            fileHashes: $fileHashes,
            totalHash: $totalHash,
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
