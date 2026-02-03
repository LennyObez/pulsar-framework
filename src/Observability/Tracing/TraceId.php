<?php

declare(strict_types=1);

namespace Pulsar\Observability\Tracing;

use function bin2hex;
use function ctype_xdigit;

use Pulsar\Observability\Tracing\Exception\TracingException;

use function random_bytes;
use function strlen;
use function strtolower;

/**
 * 128-bit trace identifier represented as 32 lowercase hex characters.
 */
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
     */
    public static function generate(): self
    {
        return new self(bin2hex(random_bytes(16)));
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
