<?php

declare(strict_types=1);

namespace Pulsar\Extension\Fhir\Smart;

use Pulsar\Api\Api;

use function explode;
use function preg_match;

/**
 * Represents a SMART on FHIR scope.
 *
 * SMART scopes follow the pattern: `<context>/<resource>.<permission>`
 *
 * - Context: "patient", "user", "system", or "launch"
 * - Resource: FHIR resource type or "*" for all
 * - Permission: "read", "write", or "*" for both
 *
 * @see http://www.hl7.org/fhir/smart-app-launch/scopes-and-launch-context.html
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class SmartScope
{
    private const string PATTERN = '/^(patient|user|system|launch)(\/([A-Za-z]+|\*)\.?(read|write|\*)?)?\s*$/';

    public function __construct(
        public string $context,
        public string $resource,
        public string $permission,
        public string $raw,
    ) {}

    /**
     * Parse a SMART scope string.
     *
     * @return self|null Parsed scope, or null if invalid
     */
    public static function parse(string $scope): ?self
    {
        $scope = trim($scope);

        // Handle launch scopes (launch, launch/patient, launch/encounter)
        if (str_starts_with($scope, 'launch')) {
            $parts = explode('/', $scope, 2);
            return new self(
                context: 'launch',
                resource: $parts[1] ?? '',
                permission: '',
                raw: $scope,
            );
        }

        if (preg_match(self::PATTERN, $scope) !== 1) {
            return null;
        }

        $slashPos = strpos($scope, '/');
        if ($slashPos === false) {
            return null;
        }

        $context = substr($scope, 0, $slashPos);
        $rest = substr($scope, $slashPos + 1);

        $dotPos = strpos($rest, '.');
        if ($dotPos === false) {
            return new self(
                context: $context,
                resource: $rest,
                permission: '*',
                raw: $scope,
            );
        }

        $resource = substr($rest, 0, $dotPos);
        $permission = substr($rest, $dotPos + 1);

        return new self(
            context: $context,
            resource: $resource,
            permission: $permission,
            raw: $scope,
        );
    }

    /**
     * Check if this scope grants access to a specific resource type and permission.
     */
    public function grants(string $resourceType, string $permission): bool
    {
        // Resource match: exact or wildcard
        if ($this->resource !== '*' && $this->resource !== $resourceType) {
            return false;
        }

        // Permission match: exact or wildcard
        if ($this->permission !== '*' && $this->permission !== $permission) {
            return false;
        }

        return true;
    }

    /**
     * Check if this is a launch scope.
     */
    public function isLaunchScope(): bool
    {
        return $this->context === 'launch';
    }

    public function __toString(): string
    {
        return $this->raw;
    }
}
