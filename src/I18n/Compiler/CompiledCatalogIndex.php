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
        /** @var array<string, array<string, list<string>>> $indexArr */
        $indexArr = is_array($index) ? $index : [];
        $fileHashes = $data['file_hashes'] ?? null;
        /** @var array<string, string> $fileHashesArr */
        $fileHashesArr = is_array($fileHashes) ? $fileHashes : [];

        return new self(
            locales: Coerce::listOfString($data['locales'] ?? null),
            index: $indexArr,
            fileHashes: $fileHashesArr,
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
