<?php

declare(strict_types=1);

namespace Pulsar\Observability\ErrorTracking;

use function hash;
use function sprintf;

use Throwable;

/**
 * Deterministic fingerprint for error grouping.
 *
 * Produces a sha256 hash of `{class}|{message}|{file}|{line}` to group
 * identical errors regardless of when they occur.
 */
final readonly class ErrorFingerprint
{
    public function __construct(
        public string $value,
    ) {}

    /**
     * Generate a fingerprint from a throwable.
     */
    public static function fromThrowable(Throwable $throwable): self
    {
        $input = sprintf(
            '%s|%s|%s|%d',
            $throwable::class,
            $throwable->getMessage(),
            $throwable->getFile(),
            $throwable->getLine(),
        );

        return new self(hash('sha256', $input));
    }

    public function toString(): string
    {
        return $this->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
