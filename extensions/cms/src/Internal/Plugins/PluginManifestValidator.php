<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Internal\Plugins;

use Pulsar\Api\Internal;
use Pulsar\Extension\Cms\Plugins\PluginCapability;
use Pulsar\Extension\Cms\Plugins\PluginManifest;
use Pulsar\Extension\Cms\Plugins\PluginManifestValidatorInterface;
use Pulsar\Extension\Cms\Themes\ValidationResult;

use function array_column;
use function in_array;
use function is_string;
use function preg_match;
use function sprintf;
use function strlen;
use function trim;

/**
 * Validates plugin manifests against required fields and format constraints.
 */
#[Internal(reason: 'Use PluginManifestValidatorInterface for public API')]
final readonly class PluginManifestValidator implements PluginManifestValidatorInterface
{
    /** Maximum slug length. */
    private const int MAX_SLUG_LENGTH = 200;

    /** Slug pattern: lowercase alphanumeric with hyphens. */
    private const string SLUG_PATTERN = '/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/';

    /** SemVer pattern. */
    private const string SEMVER_PATTERN = '/^\d+\.\d+\.\d+(?:-[\w.]+)?(?:\+[\w.]+)?$/';

    /** Version constraint pattern (^, ~, >=, allows semver-like segments). */
    private const string VERSION_CONSTRAINT_PATTERN = '/^[\^~>=<|. 0-9*]+$/';

    public function validate(PluginManifest $manifest): ValidationResult
    {
        $errors = [];
        $warnings = [];

        // Required: slug
        if (trim($manifest->slug) === '') {
            $errors[] = 'Manifest must include a "slug" field';
        } elseif (strlen($manifest->slug) > self::MAX_SLUG_LENGTH) {
            $errors[] = sprintf('Slug must not exceed %d characters', self::MAX_SLUG_LENGTH);
        } elseif (preg_match(self::SLUG_PATTERN, $manifest->slug) !== 1) {
            $errors[] = 'Slug must be lowercase alphanumeric with hyphens, starting and ending with alphanumeric';
        }

        // Required: displayName
        if (trim($manifest->displayName) === '') {
            $errors[] = 'Manifest must include a "display_name" or "name" field';
        }

        // Required: version (must be SemVer)
        if (trim($manifest->version) === '' || $manifest->version === '0.0.0') {
            $errors[] = 'Manifest must include a valid "version" field';
        } elseif (preg_match(self::SEMVER_PATTERN, $manifest->version) !== 1) {
            $errors[] = 'Version must follow SemVer format (e.g., 1.0.0, 1.2.3-beta.1)';
        }

        // Validate capabilities are known enum values
        $validCapabilities = array_column(PluginCapability::cases(), 'value');

        foreach ($manifest->capabilities as $capability) {
            if (!is_string($capability) || !in_array($capability, $validCapabilities, true)) {
                $errors[] = sprintf('Unknown capability: "%s"', $capability);
            }
        }

        // Validate dependency version constraints
        foreach ($manifest->dependencies as $depSlug => $constraint) {
            if (!is_string($constraint) || preg_match(self::VERSION_CONSTRAINT_PATTERN, $constraint) !== 1) {
                $errors[] = sprintf('Invalid version constraint for dependency "%s": "%s"', $depSlug, $constraint);
            }
        }

        // Optional warnings
        if ($manifest->description === null || trim($manifest->description) === '') {
            $warnings[] = 'Missing description — recommended for plugin marketplace listing';
        }

        if ($manifest->authorName === null || trim($manifest->authorName) === '') {
            $warnings[] = 'Missing author_name — recommended for attribution';
        }

        if ($manifest->license === null || trim($manifest->license) === '') {
            $warnings[] = 'Missing license — recommended for compliance';
        }

        if ($manifest->entryPoint === null || trim($manifest->entryPoint) === '') {
            $warnings[] = 'Missing entry_point — plugin will have no executable code';
        }

        if ($errors !== []) {
            return ValidationResult::invalid($errors, $warnings);
        }

        return ValidationResult::valid($warnings);
    }
}
