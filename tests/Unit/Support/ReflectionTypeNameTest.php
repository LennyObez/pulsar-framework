<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Support;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Support\ReflectionTypeName;
use ReflectionClass;

/**
 * The expectations here are the strings PHP's own (string) cast produces, captured
 * from the engine before the cast was replaced.
 *
 * They are hardcoded rather than compared against the cast at runtime, because the
 * cast is the deprecated behaviour this class exists to replace — asserting against
 * it would reintroduce the deprecation into the test suite.
 */
#[CoversClass(ReflectionTypeName::class)]
final class ReflectionTypeNameTest extends TestCase
{
    /**
     * @return array<string, array{string, list<string>, string}>
     */
    public static function signatures(): array
    {
        return [
            'plain named' => ['a', ['int'], 'string'],
            'nullable named keeps the leading ?' => ['b', ['?int'], '?string'],
            'union prints in reflection order, not source order' => ['c', ['string|int'], 'string|int|null'],
            'intersection' => ['d', ['Pulsar\\Tests\\Unit\\Support\\ReflectionTypeNameFixtureX&Pulsar\\Tests\\Unit\\Support\\ReflectionTypeNameFixtureY'], 'void'],
            'mixed is never decorated with ?' => ['e', ['mixed'], 'mixed'],
            'intersection inside a union is parenthesised' => [
                'f',
                ['(Pulsar\Tests\Unit\Support\ReflectionTypeNameFixtureX&Pulsar\Tests\Unit\Support\ReflectionTypeNameFixtureY)|null'],
                'void',
            ],
        ];
    }

    /**
     * @param list<string> $expectedParams
     */
    #[Test]
    #[DataProvider('signatures')]
    public function rendersExactlyWhatPhpPrints(string $method, array $expectedParams, string $expectedReturn): void
    {
        $reflection = new ReflectionClass(ReflectionTypeNameFixture::class)->getMethod($method);

        $rendered = [];

        foreach ($reflection->getParameters() as $parameter) {
            $rendered[] = ReflectionTypeName::of($parameter->getType());
        }

        self::assertSame($expectedParams, $rendered);
        self::assertSame($expectedReturn, ReflectionTypeName::of($reflection->getReturnType()));
    }

    /**
     * The absent-type case is covered here rather than through a fixture method
     * without a return type: such a method would be a real omission that the
     * analyser is right to report, and the behaviour under test is simply what
     * of() does with null.
     */
    #[Test]
    public function anAbsentTypeIsTheEmptyString(): void
    {
        self::assertSame('', ReflectionTypeName::of(null));
    }
}

interface ReflectionTypeNameFixtureX {}

interface ReflectionTypeNameFixtureY {}

final class ReflectionTypeNameFixture
{
    public function a(int $p): string
    {
        return '';
    }

    public function b(?int $p): ?string
    {
        return $p === null ? null : (string) $p;
    }

    public function c(int|string $p): int|string|null
    {
        return $p === 0 ? null : $p;
    }

    public function d(ReflectionTypeNameFixtureX&ReflectionTypeNameFixtureY $p): void {}

    public function e(mixed $p): mixed
    {
        return null;
    }

    public function f((ReflectionTypeNameFixtureX&ReflectionTypeNameFixtureY)|null $p): void {}
}
