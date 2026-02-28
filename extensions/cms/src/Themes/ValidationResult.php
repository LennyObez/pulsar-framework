<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Themes;

use Pulsar\Api\Api;

/**
 * Result of a theme manifest validation.
 *
 * @psalm-api Public DTO returned from ThemeManifestValidatorInterface and
 *            PluginManifestValidatorInterface; consumed by manager classes.
 */
#[Api(since: '1.0.0')]
final readonly class ValidationResult
{
    /**
     * @param bool $isValid Whether the manifest passed validation
     * @param list<string> $errors Validation errors (empty when valid)
     * @param list<string> $warnings Non-blocking validation warnings
     */
    public function __construct(
        public bool $isValid,
        public array $errors = [],
        public array $warnings = [],
    ) {}

    /**
     * Create a passing validation result.
     *
     * @param list<string> $warnings
     */
    public static function valid(array $warnings = []): self
    {
        return new self(isValid: true, errors: [], warnings: $warnings);
    }

    /**
     * Create a failing validation result.
     *
     * @param list<string> $errors
     * @param list<string> $warnings
     */
    public static function invalid(array $errors, array $warnings = []): self
    {
        return new self(isValid: false, errors: $errors, warnings: $warnings);
    }
}
