<?php

declare(strict_types=1);

namespace Pulsar\I18n\Extractor;

use Pulsar\Api\Internal;

use function count;

/**
 * Result of translation key extraction.
 *
 * Maps domain -> key -> list of source file references.
 */
#[Internal]
readonly class ExtractionResult
{
    /**
     * @param array<string, array<string, list<string>>> $keys domain -> key -> references
     */
    public function __construct(
        public array $keys,
    ) {}

    /**
     * Total number of unique keys across all domains.
     */
    public function totalKeys(): int
    {
        $count = 0;

        foreach ($this->keys as $domainKeys) {
            $count += count($domainKeys);
        }

        return $count;
    }
}
