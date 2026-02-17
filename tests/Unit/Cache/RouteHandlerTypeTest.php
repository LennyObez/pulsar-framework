<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Cache;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Cache\RouteHandlerType;

#[CoversClass(RouteHandlerType::class)]
final class RouteHandlerTypeTest extends TestCase
{
    #[Test]
    public function invokableHasCorrectValue(): void
    {
        self::assertSame('invokable', RouteHandlerType::Invokable->value);
    }

    #[Test]
    public function methodHasCorrectValue(): void
    {
        self::assertSame('method', RouteHandlerType::Method->value);
    }

    #[Test]
    #[DataProvider('backingValues')]
    public function fromReturnsCorrectCase(string $value, RouteHandlerType $expected): void
    {
        self::assertSame($expected, RouteHandlerType::from($value));
    }

    /**
     * @return iterable<string, array{string, RouteHandlerType}>
     */
    public static function backingValues(): iterable
    {
        yield 'invokable' => ['invokable', RouteHandlerType::Invokable];
        yield 'method' => ['method', RouteHandlerType::Method];
    }

    #[Test]
    public function tryFromReturnsNullForUnknownValue(): void
    {
        self::assertNull(RouteHandlerType::tryFrom('closure'));
    }

    #[Test]
    public function casesReturnsBothValues(): void
    {
        $cases = RouteHandlerType::cases();

        self::assertCount(2, $cases);
    }
}
