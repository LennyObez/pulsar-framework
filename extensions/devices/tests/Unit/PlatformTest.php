<?php

declare(strict_types=1);

namespace Pulsar\Extension\Devices\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Devices\Platform;

final class PlatformTest extends TestCase
{
    #[Test]
    #[DataProvider('allCasesProvider')]
    public function allCasesHaveStringValues(Platform $case, string $expected): void
    {
        self::assertSame($expected, $case->value);
    }

    /**
     * @return iterable<string, array{Platform, string}>
     */
    public static function allCasesProvider(): iterable
    {
        yield 'Android' => [Platform::Android, 'android'];
        yield 'iOS' => [Platform::iOS, 'ios'];
        yield 'Web' => [Platform::Web, 'web'];
    }

    #[Test]
    public function fromStringValue(): void
    {
        self::assertSame(Platform::Android, Platform::from('android'));
        self::assertSame(Platform::iOS, Platform::from('ios'));
        self::assertSame(Platform::Web, Platform::from('web'));
    }

    #[Test]
    public function tryFromReturnsNullForInvalid(): void
    {
        self::assertNull(Platform::tryFrom('desktop'));
    }

    #[Test]
    public function casesReturnsAllThreeValues(): void
    {
        self::assertCount(3, Platform::cases());
    }
}
