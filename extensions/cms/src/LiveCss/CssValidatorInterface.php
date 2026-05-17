<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\LiveCss;

use Pulsar\Api\Api;

/**
 * Validates CSS content for security, rejecting dangerous constructs.
 *
 * @psalm-api Public binding contract; implemented by CssValidator and
 *            consumed by LiveCssService.
 * @api
 */
#[Api(since: '1.0.0')]
interface CssValidatorInterface
{
    /**
     * Validate CSS content and return a result with sanitized output.
     */
    public function validate(string $cssContent): CssValidationResult;
}
