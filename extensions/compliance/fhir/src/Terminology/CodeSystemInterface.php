<?php

declare(strict_types=1);

namespace Pulsar\Extension\Fhir\Terminology;

use Pulsar\Api\Api;

/**
 * Contract for a FHIR code system (e.g. SNOMED-CT, LOINC, ICD-10).
 *
 * A code system defines a set of codes with their meanings. Implementations
 * may load code definitions from files, databases, or external APIs.
 *
 * @see https://www.hl7.org/fhir/codesystem.html
 * @api
 */
#[Api(since: '1.0.0')]
interface CodeSystemInterface
{
    /**
     * Get the canonical URL identifying this code system.
     */
    public function url(): string;

    /**
     * Get the human-readable name of this code system.
     */
    public function name(): string;

    /**
     * Look up a concept by its code.
     *
     * @return ConceptDefinition|null The concept, or null if not found
     */
    public function lookup(string $code): ?ConceptDefinition;

    /**
     * Validate whether a code exists in this code system.
     */
    public function validate(string $code): bool;

    /**
     * Get the version of this code system.
     */
    public function version(): string;
}
