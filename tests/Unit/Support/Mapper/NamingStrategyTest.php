<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Support\Mapper;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Support\Mapper\NamingStrategy;

#[CoversClass(NamingStrategy::class)]
final class NamingStrategyTest extends TestCase
{
    #[Test]
    #[DataProvider('strategyProvider')]
    public function caseHasCorrectStringValue(NamingStrategy $strategy, string $expected): void
    {
        self::assertSame($expected, $strategy->value);
    }

    /**
     * @return iterable<string, array{NamingStrategy, string}>
     */
    public static function strategyProvider(): iterable
    {
        yield 'Identity' => [NamingStrategy::Identity, 'identity'];
        yield 'CamelToSnake' => [NamingStrategy::CamelToSnake, 'camel_to_snake'];
        yield 'SnakeToCamel' => [NamingStrategy::SnakeToCamel, 'snake_to_camel'];
    }

    #[Test]
    public function allCasesAreMapped(): void
    {
        self::assertCount(3, NamingStrategy::cases());
    }

    #[Test]
    public function fromReturnsValidCase(): void
    {
        self::assertSame(NamingStrategy::CamelToSnake, NamingStrategy::from('camel_to_snake'));
    }

    #[Test]
    public function tryFromReturnsNullForUnknown(): void
    {
        self::assertNull(NamingStrategy::tryFrom('pascal_case'));
    }
}
