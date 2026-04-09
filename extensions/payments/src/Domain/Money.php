<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Domain;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Extension\Payments\Exception\MoneyException;

use function sprintf;

/**
 * Immutable money value object using integer minor units.
 *
 * All arithmetic operations return new instances. Cross-currency
 * operations are rejected at the type level.
 */
#[Api(since: '1.0.0')]
final readonly class Money
{
    private function __construct(
        public int $amount,
        public Currency $currency,
    ) {}

    /**
     * Create a Money instance.
     *
     * @param int $amount Amount in minor units (e.g., cents)
     * @param Currency $currency The currency
     *
     * @throws MoneyException If amount is negative
     */
    #[NoDiscard]
    public static function of(int $amount, Currency $currency): self
    {
        if ($amount < 0) {
            throw MoneyException::negativeAmount($amount);
        }

        return new self($amount, $currency);
    }

    /**
     * Create a zero Money instance for the given currency.
     */
    #[NoDiscard]
    public static function zero(Currency $currency): self
    {
        return new self(0, $currency);
    }

    /**
     * Add another Money of the same currency.
     *
     * @throws MoneyException On currency mismatch or integer overflow.
     */
    #[NoDiscard]
    public function add(self $other): self
    {
        $this->assertSameCurrency($other);

        // F21.8: both operands are non-negative ints (`Money::of()`
        // rejects negative amounts), so the only overflow direction
        // is upward. Detect via `$a > PHP_INT_MAX - $b` before the
        // addition rather than after — a post-hoc `< 0` check would
        // already have suffered the silent 64-bit wrap.
        if ($this->amount > PHP_INT_MAX - $other->amount) {
            throw MoneyException::overflow('add', $this->amount, $other->amount);
        }

        return new self($this->amount + $other->amount, $this->currency);
    }

    /**
     * Subtract another Money of the same currency.
     *
     * @throws MoneyException On currency mismatch or negative result
     */
    #[NoDiscard]
    public function subtract(self $other): self
    {
        $this->assertSameCurrency($other);

        $result = $this->amount - $other->amount;
        if ($result < 0) {
            throw MoneyException::negativeAmount($result);
        }

        return new self($result, $this->currency);
    }

    /**
     * Multiply by a non-negative integer factor.
     *
     * @throws MoneyException If factor is negative or the product
     *                       would overflow PHP_INT_MAX.
     */
    #[NoDiscard]
    public function multiply(int $factor): self
    {
        if ($factor < 0) {
            throw MoneyException::negativeMultiplier($factor);
        }

        // F21.8: detect upward overflow before the multiplication.
        // Special-case `$factor === 0` to avoid division-by-zero in
        // the bound check.
        if ($factor !== 0 && $this->amount > intdiv(PHP_INT_MAX, $factor)) {
            throw MoneyException::overflow('multiply', $this->amount, $factor);
        }

        return new self($this->amount * $factor, $this->currency);
    }

    /**
     * Compute a percentage using basis points (10000 = 100%).
     *
     * @param int $basisPoints Percentage in basis points
     * @param RoundingMode $mode Rounding mode for fractional results
     *
     * @throws MoneyException If basis points is negative or the
     *                       intermediate `amount * basisPoints` product
     *                       would overflow PHP_INT_MAX (F21.8).
     */
    #[NoDiscard]
    public function percentage(int $basisPoints, RoundingMode $mode = RoundingMode::HalfUp): self
    {
        if ($basisPoints < 0) {
            throw MoneyException::invalidBasisPoints($basisPoints);
        }

        // F21.8: the implicit cast through float in
        // `($amount * $basisPoints) / 10000` masks the overflow only
        // because the result is truncated to int by `applyRounding()`.
        // Guard the int product up-front so a 100 % rate (`basisPoints
        // = 10000`) on `PHP_INT_MAX / 9999` does not silently wrap.
        if ($basisPoints !== 0 && $this->amount > intdiv(PHP_INT_MAX, $basisPoints)) {
            throw MoneyException::overflow('multiply', $this->amount, $basisPoints);
        }

        $raw = ($this->amount * $basisPoints) / 10000;
        $rounded = self::applyRounding($raw, $mode);

        return new self($rounded, $this->currency);
    }

    /**
     * Distribute this amount into N parts, distributing remainder one unit at a time.
     *
     * @param int $parts Number of parts to allocate into
     *
     * @return list<self> Exactly $parts Money instances that sum to this amount
     *
     * @throws MoneyException If parts is not positive
     */
    #[NoDiscard]
    public function allocate(int $parts): array
    {
        if ($parts <= 0) {
            throw MoneyException::invalidParts($parts);
        }

        $base = intdiv($this->amount, $parts);
        $remainder = $this->amount % $parts;

        $result = [];
        for ($i = 0; $i < $parts; $i++) {
            $result[] = new self($base + ($i < $remainder ? 1 : 0), $this->currency);
        }

        return $result;
    }

    /**
     * Format the amount using the currency's minor digits.
     *
     * Example: Money::of(1050, Currency::USD)->format() => "10.50"
     * Example: Money::of(1000, Currency::JPY)->format() => "1000"
     */
    #[NoDiscard]
    public function format(): string
    {
        $digits = $this->currency->minorDigits();

        if ($digits === 0) {
            return (string) $this->amount;
        }

        $divisor = 10 ** $digits;
        $whole = intdiv($this->amount, $divisor);
        $fraction = $this->amount % $divisor;

        return sprintf('%d.%0' . $digits . 'd', $whole, $fraction);
    }

    /**
     * Check if this Money represents zero.
     */
    public function isZero(): bool
    {
        return $this->amount === 0;
    }

    /**
     * Check if two Money instances are equal in amount and currency.
     */
    public function equals(self $other): bool
    {
        return $this->amount === $other->amount && $this->currency === $other->currency;
    }

    /**
     * @throws MoneyException
     */
    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw MoneyException::currencyMismatch($this->currency, $other->currency);
        }
    }

    private static function applyRounding(float $value, RoundingMode $mode): int
    {
        return match ($mode) {
            RoundingMode::HalfUp => (int) round($value, 0, PHP_ROUND_HALF_UP),
            RoundingMode::HalfDown => (int) round($value, 0, PHP_ROUND_HALF_DOWN),
            RoundingMode::HalfEven => (int) round($value, 0, PHP_ROUND_HALF_EVEN),
            RoundingMode::Floor => (int) floor($value),
            RoundingMode::Ceiling => (int) ceil($value),
        };
    }
}
