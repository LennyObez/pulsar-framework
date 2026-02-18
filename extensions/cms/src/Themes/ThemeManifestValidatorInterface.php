<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Themes;

use Pulsar\Api\Api;

/**
 * Validates theme manifest files (theme.json).
 */
#[Api(since: '1.0.0')]
interface ThemeManifestValidatorInterface
{
    /**
     * Validate a parsed theme manifest.
     */
    public function validate(ThemeManifest $manifest): ValidationResult;
}
