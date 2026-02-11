<?php

declare(strict_types=1);

namespace Pulsar\Extension\Accessibility\Validator;

use Pulsar\Api\Api;

/**
 * Represents a single accessibility violation found during validation.
 */
#[Api(since: '1.0.0')]
final readonly class AccessibilityViolation
{
    public function __construct(
        public string $rule,
        public Severity $severity,
        public string $element,
        public string $message,
        public string $wcagCriterion,
        public ?int $line = null,
    ) {}
}
