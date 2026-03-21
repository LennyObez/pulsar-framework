<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\EInvoicing;

use Pulsar\Api\Api;

/**
 * A single EN 16931 validation violation.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class ValidationViolation
{
    public function __construct(
        public string $field,
        public string $message,
        public string $rule,
    ) {}
}
