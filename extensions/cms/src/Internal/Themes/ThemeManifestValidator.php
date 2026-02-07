<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Internal\Themes;

use Pulsar\Api\Internal;
use Pulsar\Extension\Cms\Themes\ThemeManifest;
use Pulsar\Extension\Cms\Themes\ThemeManifestValidatorInterface;
use Pulsar\Extension\Cms\Themes\ValidationResult;

use function preg_match;
use function sprintf;
use function strlen;
use function trim;

/**
 * Validates theme manifests against required fields and format constraints.
 */
#[Internal(reason: 'Use ThemeManifestValidatorInterface for public API')]
final readonly class ThemeManifestValidator implements ThemeManifestValidatorInterface
{
    /** Maximum slug length. */
    private const int MAX_SLUG_LENGTH = 200;

    /** Slug pattern: lowercase alphanumeric with hyphens. */
    private const string SLUG_PATTERN = '/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/';

    /** SemVer pattern. */
    private const string SEMVER_PATTERN = '/^\d+\.\d+\.\d+(?:-[\w.]+)?(?:\+[\w.]+)?$/';

    public function validate(ThemeManifest $manifest): ValidationResult
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

        // Optional warnings
        if ($manifest->description === null || trim($manifest->description) === '') {
            $warnings[] = 'Missing description — recommended for theme marketplace listing';
        }

        if ($manifest->authorName === null || trim($manifest->authorName) === '') {
            $warnings[] = 'Missing author_name — recommended for attribution';
        }

        if ($manifest->license === null || trim($manifest->license) === '') {
            $warnings[] = 'Missing license — recommended for compliance';
        }

        if ($manifest->regions === []) {
            $warnings[] = 'No regions declared — theme may not render correctly';
        }

        if ($errors !== []) {
            return ValidationResult::invalid($errors, $warnings);
        }

        return ValidationResult::valid($warnings);
    }
}
