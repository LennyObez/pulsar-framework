<?php

declare(strict_types=1);

namespace Pulsar\Observability\Tracing;

use function bin2hex;
use function ctype_xdigit;

use NoDiscard;
use Pulsar\Observability\Tracing\Exception\TracingException;
use Random\Engine\Secure;
use Random\RandomException;
use Random\Randomizer;

use function strlen;
use function strtolower;

/**
 * 64-bit span identifier represented as 16 lowercase hex characters.
 */
final readonly class SpanId
{
    public string $value;

    public function __construct(string $value)
    {
        $normalized = strtolower($value);

        if (strlen($normalized) !== 16 || !ctype_xdigit($normalized)) {
            throw TracingException::invalidSpanId($value);
        }

        $this->value = $normalized;
    }

    /**
     * Generate a new random span ID.
     *
     * @throws RandomException
     */
    #[NoDiscard]
    public static function generate(?Randomizer $randomizer = null): self
    {
        $randomizer ??= new Randomizer(new Secure());

        return new self(bin2hex($randomizer->getBytes(8)));
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
