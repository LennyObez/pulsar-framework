<?php

declare(strict_types=1);

namespace Pulsar\Extension\Fhir\Terminology;

use Pulsar\Api\Api;

/**
 * Specifies the contents of a code system within a ValueSet.
 *
 * @see https://www.hl7.org/fhir/valueset-definitions.html#ValueSet.compose.include
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class ValueSetInclude
{
    /**
     * @param list<ConceptDefinition> $concepts Explicitly included concepts (empty = entire system)
     */
    public function __construct(
        public string $system,
        public ?string $version = null,
        public array $concepts = [],
    ) {}
}
