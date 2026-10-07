<?php

declare(strict_types=1);

namespace Pulsar\Extension\Accessibility\Validator;

use Pulsar\Api\Api;

/**
 * Contract for accessibility validators that analyze HTML for WCAG compliance.
 * @api
 */
#[Api(since: '1.0.0')]
interface ValidatorInterface
{
    /**
     * Validate HTML content and return a list of accessibility violations.
     *
     * @return list<AccessibilityViolation>
     */
    public function validate(string $html): array;
}
