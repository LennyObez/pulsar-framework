<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\LiveCss;

use Pulsar\Api\Api;

/**
 * Result of CSS content validation, including sanitized output.
 */
#[Api(since: '1.0.0')]
final readonly class CssValidationResult
{
    /**
     * @param bool $isValid Whether the CSS passed all security checks
     * @param list<string> $errors Specific violation messages
     * @param string $sanitizedCss Input with dangerous constructs removed
     */
    public function __construct(
        public bool $isValid,
        public array $errors,
        public string $sanitizedCss,
    ) {}
}
