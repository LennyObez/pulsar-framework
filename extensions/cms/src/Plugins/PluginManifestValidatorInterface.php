<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Plugins;

use Pulsar\Api\Api;
use Pulsar\Extension\Cms\Themes\ValidationResult;

/**
 * Validates plugin manifest files (plugin.json).
 */
#[Api(since: '1.0.0')]
interface PluginManifestValidatorInterface
{
    /**
     * Validate a parsed plugin manifest.
     */
    public function validate(PluginManifest $manifest): ValidationResult;
}
