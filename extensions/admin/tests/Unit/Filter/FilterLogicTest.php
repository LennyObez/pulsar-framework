<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Tests\Unit\Filter;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Admin\Filter\FilterLogic;

#[CoversClass(FilterLogic::class)]
final class FilterLogicTest extends TestCase
{
    #[Test]
    public function andHasCorrectValue(): void
    {
        self::assertSame('and', FilterLogic::And->value);
    }

    #[Test]
    public function orHasCorrectValue(): void
    {
        self::assertSame('or', FilterLogic::Or->value);
    }

    #[Test]
    public function casesReturnsBoth(): void
    {
        self::assertCount(2, FilterLogic::cases());
    }

    #[Test]
    public function tryFromReturnsNullForInvalid(): void
    {
        self::assertNull(FilterLogic::tryFrom('xor'));
    }

    #[Test]
    public function fromConstructsFromString(): void
    {
        self::assertSame(FilterLogic::And, FilterLogic::from('and'));
        self::assertSame(FilterLogic::Or, FilterLogic::from('or'));
    }
}
