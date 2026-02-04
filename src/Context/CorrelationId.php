<?php

declare(strict_types=1);

namespace Pulsar\Context;

use function bin2hex;
use function ctype_xdigit;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Context\Exception\ContextException;
use Random\Engine\Secure;
use Random\RandomException;
use Random\Randomizer;

use function strlen;
use function strtolower;

/**
 * Correlation identifier for tracking operations across system boundaries.
 *
 * 128-bit (32 lowercase hex characters) value propagated through HTTP,
 * queue, scheduler, and CLI contexts.
 */
#[Api(since: '1.0.0')]
final readonly class CorrelationId
{
    public string $value;

    public function __construct(string $value)
    {
        $normalized = strtolower($value);

        if (strlen($normalized) !== 32 || !ctype_xdigit($normalized)) {
            throw ContextException::invalidCorrelationId($value);
        }

        $this->value = $normalized;
    }

    /**
     * Generate a new random correlation ID.
     *
     * @throws RandomException
     */
    #[NoDiscard]
    public static function generate(?Randomizer $randomizer = null): self
    {
        $randomizer ??= new Randomizer(new Secure());

        return new self(bin2hex($randomizer->getBytes(16)));
    }

    /**
     * Create from an existing hex string.
     */
    #[NoDiscard]
    public static function fromString(string $value): self
    {
        return new self($value);
    }

    public function toString(): string
    {
        return $this->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
