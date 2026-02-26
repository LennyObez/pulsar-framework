<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\LiveCss;

use Pulsar\Api\Api;

/**
 * Validates CSS content for security, rejecting dangerous constructs.
 */
#[Api(since: '1.0.0')]
interface CssValidatorInterface
{
    /**
     * Validate CSS content and return a result with sanitized output.
     */
    public function validate(string $cssContent): CssValidationResult;
}
