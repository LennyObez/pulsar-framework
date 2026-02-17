<?php

declare(strict_types=1);

namespace Pulsar\Extension\MedicalDevices\Udi;

use Pulsar\Api\Api;

/**
 * Result of a UDI format validation.
 */
#[Api(since: '1.0.0')]
final readonly class UdiValidationResult
{
    public function __construct(
        public bool $valid,
        public ?string $message = null,
    ) {}
}
