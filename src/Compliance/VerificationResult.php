<?php

declare(strict_types=1);

namespace Pulsar\Compliance;

use Pulsar\Api\Api;

/**
 * Result of a control verification check.
 */
#[Api(since: '1.0.0')]
final readonly class VerificationResult
{
    public function __construct(
        public string $controlId,
        public bool $passed,
        public string $message = '',
        public ?int $verifiedAt = null,
    ) {}

    public static function pass(string $controlId, string $message = 'Control verified'): self
    {
        return new self(
            controlId: $controlId,
            passed: true,
            message: $message,
            verifiedAt: time(),
        );
    }

    public static function fail(string $controlId, string $message): self
    {
        return new self(
            controlId: $controlId,
            passed: false,
            message: $message,
            verifiedAt: time(),
        );
    }
}
