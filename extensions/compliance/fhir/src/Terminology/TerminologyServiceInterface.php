<?php

declare(strict_types=1);

namespace Pulsar\Extension\Fhir\Terminology;

use Pulsar\Api\Api;

/**
 * Facade for terminology operations: lookup, validate, and translate.
 *
 * Aggregates code systems, value sets, and concept maps to provide
 * a unified terminology service for FHIR applications.
 *
 * @see https://www.hl7.org/fhir/terminology-service.html
 * @api
 */
#[Api(since: '1.0.0')]
interface TerminologyServiceInterface
{
    /**
     * Look up a concept by code in a code system.
     *
     * @param string $system The code system URL
     * @param string $code   The code to look up
     */
    public function lookup(string $system, string $code): ?ConceptDefinition;

    /**
     * Validate a code against a ValueSet.
     *
     * @param string $valueSetUrl The ValueSet URL
     * @param string $system      The code system URL
     * @param string $code        The code to validate
     */
    public function validate(string $valueSetUrl, string $system, string $code): ValidationResult;

    /**
     * Translate a code from one code system to another using a ConceptMap.
     *
     * @param string $conceptMapUrl The ConceptMap URL
     * @param string $sourceCode    The code to translate
     *
     * @return list<ConceptMapEntry> Matching translations
     */
    public function translate(string $conceptMapUrl, string $sourceCode): array;
}
