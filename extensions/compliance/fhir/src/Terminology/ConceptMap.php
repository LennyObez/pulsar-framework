<?php

declare(strict_types=1);

namespace Pulsar\Extension\Fhir\Terminology;

use Pulsar\Api\Api;

/**
 * A mapping between concepts in different code systems.
 *
 * ConceptMaps allow translation of codes between systems (e.g., ICD-10 to SNOMED-CT).
 *
 * @see https://www.hl7.org/fhir/conceptmap.html
 */
#[Api(since: '1.0.0')]
final class ConceptMap
{
    /** @var array<string, list<ConceptMapEntry>> Source key => list of targets */
    private array $mappings = [];

    public function __construct(
        public readonly string $url,
        public readonly string $name,
        public readonly string $sourceSystem,
        public readonly string $targetSystem,
    ) {}

    /**
     * Add a mapping from a source code to a target code.
     */
    public function addMapping(
        string $sourceCode,
        string $targetCode,
        string $equivalence = 'equivalent',
        string $comment = '',
    ): void {
        $key = $this->sourceSystem . '|' . $sourceCode;

        $this->mappings[$key][] = new ConceptMapEntry(
            sourceCode: $sourceCode,
            targetCode: $targetCode,
            equivalence: $equivalence,
            comment: $comment,
        );
    }

    /**
     * Translate a source code to its target(s).
     *
     * @return list<ConceptMapEntry> All mappings for the given source code
     */
    public function translate(string $sourceCode): array
    {
        $key = $this->sourceSystem . '|' . $sourceCode;

        return $this->mappings[$key] ?? [];
    }

    /**
     * Check if a mapping exists for a source code.
     */
    public function hasMapping(string $sourceCode): bool
    {
        $key = $this->sourceSystem . '|' . $sourceCode;

        return isset($this->mappings[$key]);
    }
}
