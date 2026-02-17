<?php

declare(strict_types=1);

namespace Pulsar\Extension\Releases\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Releases\ReleasePlatform;

final class ReleasePlatformTest extends TestCase
{
    #[Test]
    #[DataProvider('allCasesProvider')]
    public function allCasesHaveStringValues(ReleasePlatform $case, string $expected): void
    {
        self::assertSame($expected, $case->value);
    }

    /**
     * @return iterable<string, array{ReleasePlatform, string}>
     */
    public static function allCasesProvider(): iterable
    {
        yield 'Android' => [ReleasePlatform::Android, 'android'];
        yield 'iOS' => [ReleasePlatform::Ios, 'ios'];
        yield 'Web' => [ReleasePlatform::Web, 'web'];
    }

    #[Test]
    public function casesReturnsThreeValues(): void
    {
        self::assertCount(3, ReleasePlatform::cases());
    }

    #[Test]
    public function tryFromReturnsNullForInvalid(): void
    {
        self::assertNull(ReleasePlatform::tryFrom('desktop'));
    }
}
