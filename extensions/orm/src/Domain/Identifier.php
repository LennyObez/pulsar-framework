<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Domain;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * Validated SQL identifier value object.
 *
 * Fail-fast validation at construction time ensures no invalid identifiers
 * leak into compiled SQL.
 */
#[Api(since: '1.0.0')]
final readonly class Identifier
{
    private function __construct(
        public string $value,
    ) {}

    #[NoDiscard]
    public static function from(string $value): self
    {
        IdentifierValidator::validate($value);

        return new self($value);
    }

    public function toString(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
