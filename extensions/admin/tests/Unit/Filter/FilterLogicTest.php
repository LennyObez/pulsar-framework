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
    public function and_has_correct_value(): void
    {
        self::assertSame('and', FilterLogic::And->value);
    }

    #[Test]
    public function or_has_correct_value(): void
    {
        self::assertSame('or', FilterLogic::Or->value);
    }

    #[Test]
    public function cases_returns_both(): void
    {
        self::assertCount(2, FilterLogic::cases());
    }

    #[Test]
    public function try_from_returns_null_for_invalid(): void
    {
        self::assertNull(FilterLogic::tryFrom('xor'));
    }

    #[Test]
    public function from_constructs_from_string(): void
    {
        self::assertSame(FilterLogic::And, FilterLogic::from('and'));
        self::assertSame(FilterLogic::Or, FilterLogic::from('or'));
    }
}
