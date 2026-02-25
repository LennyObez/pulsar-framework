<?php

declare(strict_types=1);

namespace Pulsar\Extension\Payments\Tests\Unit\Exception;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Payments\Domain\Currency;
use Pulsar\Extension\Payments\Exception\MoneyException;

final class MoneyExceptionTest extends TestCase
{
    #[Test]
    public function currencyMismatchContainsBothCurrencies(): void
    {
        $e = MoneyException::currencyMismatch(Currency::USD, Currency::EUR);

        self::assertStringContainsString('USD', $e->getMessage());
        self::assertStringContainsString('EUR', $e->getMessage());
    }

    #[Test]
    public function negativeAmountContainsValue(): void
    {
        $e = MoneyException::negativeAmount(-500);

        self::assertStringContainsString('-500', $e->getMessage());
    }

    #[Test]
    public function invalidPartsContainsValue(): void
    {
        $e = MoneyException::invalidParts(0);

        self::assertStringContainsString('0', $e->getMessage());
    }

    #[Test]
    public function negativeMultiplierContainsValue(): void
    {
        $e = MoneyException::negativeMultiplier(-2);

        self::assertStringContainsString('-2', $e->getMessage());
    }

    #[Test]
    public function invalidBasisPointsContainsValue(): void
    {
        $e = MoneyException::invalidBasisPoints(-100);

        self::assertStringContainsString('-100', $e->getMessage());
    }
}
