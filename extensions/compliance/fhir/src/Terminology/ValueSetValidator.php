<?php

declare(strict_types=1);

namespace Pulsar\Extension\Fhir\Terminology;

use Pulsar\Api\Api;

/**
 * Validates coded values against ValueSet definitions.
 *
 * A ValueSet defines a set of codes drawn from one or more code systems
 * that are allowed in a particular context. This validator checks whether
 * a given code + system combination is part of a defined ValueSet.
 *
 * @see https://www.hl7.org/fhir/valueset.html
 * @api
 */
#[Api(since: '1.0.0')]
final class ValueSetValidator
{
    /** @var array<string, ValueSetDefinition> URL => definition */
    private array $valueSets = [];

    /**
     * Register a ValueSet definition.
     */
    public function register(ValueSetDefinition $valueSet): void
    {
        $this->valueSets[$valueSet->url] = $valueSet;
    }

    /**
     * Validate a code against a ValueSet.
     *
     * @return ValidationResult The validation result with display and any errors
     */
    public function validate(string $valueSetUrl, string $system, string $code): ValidationResult
    {
        $valueSet = $this->valueSets[$valueSetUrl] ?? null;

        if ($valueSet === null) {
            return new ValidationResult(
                valid: false,
                message: "ValueSet '$valueSetUrl' not found",
            );
        }

        foreach ($valueSet->includes as $include) {
            if ($include->system !== $system) {
                continue;
            }

            // If concepts are explicitly listed, check against them
            if ($include->concepts !== []) {
                foreach ($include->concepts as $concept) {
                    if ($concept->code === $code) {
                        return new ValidationResult(
                            valid: true,
                            display: $concept->display,
                        );
                    }
                }
                continue;
            }

            // If no concepts are listed, the entire system is included
            return new ValidationResult(
                valid: true,
                message: "Code '$code' accepted from system '$system' (entire system included)",
            );
        }

        return new ValidationResult(
            valid: false,
            message: "Code '$code' from system '$system' not found in ValueSet '$valueSetUrl'",
        );
    }

    /**
     * Get a registered ValueSet by URL.
     */
    public function get(string $url): ?ValueSetDefinition
    {
        return $this->valueSets[$url] ?? null;
    }
}
