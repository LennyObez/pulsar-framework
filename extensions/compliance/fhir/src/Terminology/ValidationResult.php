<?php

declare(strict_types=1);

namespace Pulsar\Extension\Fhir\Terminology;

use Pulsar\Api\Api;

/**
 * Result of a terminology validation operation.
 */
#[Api(since: '1.0.0')]
final readonly class ValidationResult
{
    public function __construct(
        public bool $valid,
        public ?string $display = null,
        public ?string $message = null,
    ) {}
}
