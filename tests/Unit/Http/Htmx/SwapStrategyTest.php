<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Http\Htmx;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Htmx\SwapStrategy;

#[CoversNothing]
final class SwapStrategyTest extends TestCase
{
    /**
     * @return iterable<string, array{SwapStrategy, string}>
     */
    public static function strategyProvider(): iterable
    {
        yield 'innerHTML' => [SwapStrategy::InnerHTML, 'innerHTML'];
        yield 'outerHTML' => [SwapStrategy::OuterHTML, 'outerHTML'];
        yield 'beforebegin' => [SwapStrategy::BeforeBegin, 'beforebegin'];
        yield 'afterbegin' => [SwapStrategy::AfterBegin, 'afterbegin'];
        yield 'beforeend' => [SwapStrategy::BeforeEnd, 'beforeend'];
        yield 'afterend' => [SwapStrategy::AfterEnd, 'afterend'];
        yield 'delete' => [SwapStrategy::Delete, 'delete'];
        yield 'none' => [SwapStrategy::None, 'none'];
    }

    #[Test]
    #[DataProvider('strategyProvider')]
    public function strategy_has_correct_value(SwapStrategy $strategy, string $expected): void
    {
        self::assertSame($expected, $strategy->value);
    }

    #[Test]
    public function all_strategies_backed_by_string(): void
    {
        self::assertCount(8, SwapStrategy::cases());
    }

    #[Test]
    public function from_string_value(): void
    {
        self::assertSame(SwapStrategy::OuterHTML, SwapStrategy::from('outerHTML'));
    }

    #[Test]
    public function try_from_invalid_returns_null(): void
    {
        self::assertNull(SwapStrategy::tryFrom('invalid'));
    }
}
