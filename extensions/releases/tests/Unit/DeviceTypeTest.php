<?php

declare(strict_types=1);

namespace Pulsar\Extension\Releases\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Releases\DeviceType;

final class DeviceTypeTest extends TestCase
{
    #[Test]
    #[DataProvider('allCasesProvider')]
    public function allCasesHaveStringValues(DeviceType $case, string $expected): void
    {
        self::assertSame($expected, $case->value);
    }

    /**
     * @return iterable<string, array{DeviceType, string}>
     */
    public static function allCasesProvider(): iterable
    {
        yield 'Android' => [DeviceType::Android, 'android'];
        yield 'iOS' => [DeviceType::Ios, 'ios'];
        yield 'Both' => [DeviceType::Both, 'both'];
    }

    #[Test]
    public function casesReturnsThreeValues(): void
    {
        self::assertCount(3, DeviceType::cases());
    }

    #[Test]
    public function tryFromReturnsNullForInvalid(): void
    {
        self::assertNull(DeviceType::tryFrom('windows'));
    }
}
