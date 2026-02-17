<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Domain;

use DateTimeImmutable;
use NoDiscard;
use Pulsar\Api\Api;

/**
 * Result of verifying a mobile purchase token against a store API.
 */
#[Api(since: '1.0.0')]
final readonly class MobileVerificationResult
{
    public function __construct(
        public bool $isValid,
        public ?DateTimeImmutable $expiresAt,
        public ?DateTimeImmutable $gracePeriodUntil,
        public string $productId,
        public bool $autoRenewing,
    ) {}

    /**
     * Create a result indicating an invalid or unverifiable purchase.
     */
    #[NoDiscard]
    public static function invalid(): self
    {
        return new self(
            isValid: false,
            expiresAt: null,
            gracePeriodUntil: null,
            productId: '',
            autoRenewing: false,
        );
    }
}
