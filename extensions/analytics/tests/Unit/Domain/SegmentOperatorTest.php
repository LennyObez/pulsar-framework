<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Tests\Unit\Domain;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Analytics\Domain\SegmentOperator;

#[CoversClass(SegmentOperator::class)]
final class SegmentOperatorTest extends TestCase
{
    #[Test]
    public function hasEightCases(): void
    {
        self::assertCount(8, SegmentOperator::cases());
    }

    #[Test]
    #[DataProvider('operatorValuesProvider')]
    public function fromStringReturnsCorrectCase(string $value, SegmentOperator $expected): void
    {
        self::assertSame($expected, SegmentOperator::from($value));
    }

    /** @return iterable<string, array{string, SegmentOperator}> */
    public static function operatorValuesProvider(): iterable
    {
        yield 'eq' => ['eq', SegmentOperator::Equals];
        yield 'neq' => ['neq', SegmentOperator::NotEquals];
        yield 'contains' => ['contains', SegmentOperator::Contains];
        yield 'not_contains' => ['not_contains', SegmentOperator::NotContains];
        yield 'starts_with' => ['starts_with', SegmentOperator::StartsWith];
        yield 'gt' => ['gt', SegmentOperator::GreaterThan];
        yield 'lt' => ['lt', SegmentOperator::LessThan];
        yield 'in' => ['in', SegmentOperator::In];
    }

    #[Test]
    public function tryFromReturnsNullForInvalid(): void
    {
        self::assertNull(SegmentOperator::tryFrom('invalid'));
    }
}
