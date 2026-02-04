<?php

declare(strict_types=1);

namespace Pulsar\Observability\Tracing;

use function bin2hex;
use function ctype_xdigit;

use Pulsar\Observability\Tracing\Exception\TracingException;
use Random\RandomException;

use function random_bytes;
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
    public static function generate(): self
    {
        return new self(bin2hex(random_bytes(8)));
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
