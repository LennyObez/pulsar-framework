<?php

declare(strict_types=1);

namespace Pulsar\Security\Validation;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * Result of a URL safety validation check.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class UrlValidationResult
{
    private function __construct(
        public bool $safe,
        public string $reason,
    ) {}

    #[NoDiscard]
    public static function allowed(): self
    {
        return new self(safe: true, reason: '');
    }

    #[NoDiscard]
    public static function rejected(string $reason): self
    {
        return new self(safe: false, reason: $reason);
    }
}
