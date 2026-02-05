<?php

declare(strict_types=1);

namespace Pulsar\Observability\Tracing;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Observability\Tracing\Exception\TracingException;
use Random\Engine\Secure;
use Random\RandomException;
use Random\Randomizer;

use function bin2hex;
use function ctype_xdigit;
use function strlen;
use function strtolower;

/**
 * 128-bit trace identifier represented as 32 lowercase hex characters.
 */
#[Api(since: '1.0.0')]
final readonly class TraceId
{
    public string $value;

    public function __construct(string $value)
    {
        $normalized = strtolower($value);

        if (strlen($normalized) !== 32 || !ctype_xdigit($normalized)) {
            throw TracingException::invalidTraceId($value);
        }

        $this->value = $normalized;
    }

    /**
     * Generate a new random trace ID.
     *
     * @throws RandomException
     */
    #[NoDiscard]
    public static function generate(?Randomizer $randomizer = null): self
    {
        $randomizer ??= new Randomizer(new Secure());

        return new self(bin2hex($randomizer->getBytes(16)));
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
