<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Exception;

use NoDiscard;
use Pulsar\Extension\Payments\Domain\Currency;
use RuntimeException;

/**
 * Money arithmetic exceptions.
 */
final class MoneyException extends RuntimeException
{
    #[NoDiscard]
    public static function currencyMismatch(Currency $expected, Currency $actual): self
    {
        return new self(\sprintf(
            'Currency mismatch: expected %s, got %s',
            $expected->value,
            $actual->value,
        ));
    }

    #[NoDiscard]
    public static function negativeAmount(int $amount): self
    {
        return new self(\sprintf('Money amount must be non-negative, got %d', $amount));
    }

    #[NoDiscard]
    public static function invalidParts(int $parts): self
    {
        return new self(\sprintf('Allocation parts must be positive, got %d', $parts));
    }

    #[NoDiscard]
    public static function negativeMultiplier(int $factor): self
    {
        return new self(\sprintf('Multiplier must be non-negative, got %d', $factor));
    }

    #[NoDiscard]
    public static function invalidBasisPoints(int $basisPoints): self
    {
        return new self(\sprintf('Basis points must be non-negative, got %d', $basisPoints));
    }
}
