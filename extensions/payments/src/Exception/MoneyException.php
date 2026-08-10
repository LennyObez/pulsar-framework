<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Exception;

use NoDiscard;
use Pulsar\Extension\Payments\Domain\Currency;
use RuntimeException;

use function sprintf;

/**
 * Money arithmetic exceptions.
 */
final class MoneyException extends RuntimeException
{
    #[NoDiscard]
    public static function currencyMismatch(Currency $expected, Currency $actual): self
    {
        return new self(sprintf(
            'Currency mismatch: expected %s, got %s',
            $expected->value,
            $actual->value,
        ));
    }

    #[NoDiscard]
    public static function negativeAmount(int $amount): self
    {
        return new self(sprintf('Money amount must be non-negative, got %d', $amount));
    }

    #[NoDiscard]
    public static function invalidParts(int $parts): self
    {
        return new self(sprintf('Allocation parts must be positive, got %d', $parts));
    }

    #[NoDiscard]
    public static function negativeMultiplier(int $factor): self
    {
        return new self(sprintf('Multiplier must be non-negative, got %d', $factor));
    }

    #[NoDiscard]
    public static function invalidBasisPoints(int $basisPoints): self
    {
        return new self(sprintf('Basis points must be non-negative, got %d', $basisPoints));
    }

    /**
     * Integer overflow guard. Money arithmetic on 64-bit ints
     * silently wraps to a negative number on overflow, and a banking
     * framework cannot tolerate a `999_999_999_999 + 999_999_999_999`
     * that surfaces as a credit instead of a 500.
     */
    #[NoDiscard]
    public static function overflow(string $operation, int $a, int $b): self
    {
        return new self(sprintf(
            'Money arithmetic overflow on %s: %d %s %d would exceed PHP_INT_MAX',
            $operation,
            $a,
            $operation === 'add' ? '+' : '*',
            $b,
        ));
    }
}
