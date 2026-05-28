<?php

declare(strict_types=1);

namespace Pulsar\Extension\Fhir\Internal;

use Pulsar\Api\Internal;

use function array_key_exists;
use function explode;
use function is_array;
use function is_string;
use function str_contains;
use function strtolower;

/**
 * Evaluates FHIR search parameters against a decoded resource array.
 *
 * Shared by every {@see \Pulsar\Extension\Fhir\Rest\FhirRepositoryInterface}
 * implementation so the match semantics (FHIR control params + dotted field
 * paths, case-insensitive token equality) stay identical regardless of the
 * storage backend.
 */
#[Internal(reason: 'FHIR search-parameter evaluation; backend-agnostic helper')]
final class FhirSearchMatcher
{
    /**
     * @param array<string, mixed>  $resource
     * @param array<string, string> $parameters
     */
    public static function matches(array $resource, array $parameters): bool
    {
        foreach ($parameters as $param => $value) {
            // FHIR control parameter: exact id match.
            if ($param === '_id') {
                if (($resource['id'] ?? '') !== $value) {
                    return false;
                }
                continue;
            }

            // FHIR control parameter: meta.lastUpdated substring (prefix/date) match.
            if ($param === '_lastUpdated') {
                /** @var array<string, mixed> $meta */
                $meta = is_array($resource['meta'] ?? null) ? $resource['meta'] : [];
                /** @var mixed $rawLastUpdated */
                $rawLastUpdated = $meta['lastUpdated'] ?? null;
                $lastUpdated = is_string($rawLastUpdated) ? $rawLastUpdated : '';
                if (!str_contains($lastUpdated, $value)) {
                    return false;
                }
                continue;
            }

            // Dotted field path (e.g. subject.reference): case-insensitive token equality.
            /** @var mixed $fieldValue */
            $fieldValue = self::resolveField($resource, $param);
            if ($fieldValue === null) {
                return false;
            }

            if (is_string($fieldValue) && strtolower($fieldValue) !== strtolower($value)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Resolve a dotted field path within a decoded resource.
     *
     * @param array<string, mixed> $resource
     */
    private static function resolveField(array $resource, string $path): mixed
    {
        $parts = explode('.', $path);
        /** @var mixed $current */
        $current = $resource;

        foreach ($parts as $part) {
            if (!is_array($current) || !array_key_exists($part, $current)) {
                return null;
            }
            /** @var mixed $current */
            $current = $current[$part];
        }

        return $current;
    }
}
