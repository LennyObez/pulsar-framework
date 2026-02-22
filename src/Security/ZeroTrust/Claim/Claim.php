<?php

declare(strict_types=1);

namespace Pulsar\Security\ZeroTrust\Claim;

use DateTimeImmutable;
use InvalidArgumentException;
use Pulsar\Api\Api;

use function sprintf;

/**
 * A single zero-trust claim produced by a signal provider.
 *
 * Claims are immutable assertions about a request's context (e.g., "device is registered",
 * "IP is in known range"). Each claim carries a confidence score (0.0 = no confidence,
 * 1.0 = full confidence) and is tagged with the source that produced it.
 */
#[Api(since: '1.0.0')]
readonly class Claim
{
    /**
     * @param string $name Claim identifier (e.g., "device.registered", "ip.in_range")
     * @param mixed $value Claim payload — type depends on the claim
     * @param ClaimSource $source Signal provider that produced this claim
     * @param float $confidence Confidence level, must be between 0.0 and 1.0 inclusive
     * @param DateTimeImmutable $timestamp When the claim was produced
     */
    public function __construct(
        public string $name,
        public mixed $value,
        public ClaimSource $source,
        public float $confidence,
        public DateTimeImmutable $timestamp,
    ) {
        if ($this->confidence < 0.0 || $this->confidence > 1.0) {
            throw new InvalidArgumentException(
                sprintf('Claim confidence must be between 0.0 and 1.0, got %f', $this->confidence),
            );
        }

        if ($this->name === '') {
            throw new InvalidArgumentException('Claim name must not be empty');
        }
    }
}
