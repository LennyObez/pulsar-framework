<?php

declare(strict_types=1);

namespace Pulsar\Extension\Dsa\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Dsa\Config\DsaConfig;

#[CoversClass(DsaConfig::class)]
final class DsaConfigTest extends TestCase
{
    #[Test]
    public function fromArrayWithEmptyArrayUsesDefaults(): void
    {
        $config = DsaConfig::fromArray([]);

        self::assertFalse($config->enabled);
        self::assertSame('hosting', $config->platformType);
        self::assertSame('', $config->contactPoint);
        self::assertSame('', $config->legalRepresentative);
        self::assertSame(180, $config->appealWindowDays);
        self::assertSame(24, $config->noticeResponseHours);
    }

    #[Test]
    public function fromArrayWithCustomValues(): void
    {
        $config = DsaConfig::fromArray([
            'enabled' => true,
            'platform_type' => 'vlop',
            'contact_point' => 'dsa@example.com',
            'legal_representative' => 'Legal Corp GmbH',
            'appeal_window_days' => 90,
            'notice_response_hours' => 12,
        ]);

        self::assertTrue($config->enabled);
        self::assertSame('vlop', $config->platformType);
        self::assertSame('dsa@example.com', $config->contactPoint);
        self::assertSame('Legal Corp GmbH', $config->legalRepresentative);
        self::assertSame(90, $config->appealWindowDays);
        self::assertSame(12, $config->noticeResponseHours);
    }

    #[Test]
    public function fromArrayIgnoresInvalidTypes(): void
    {
        $config = DsaConfig::fromArray([
            'enabled' => 'yes',
            'platform_type' => 123,
            'appeal_window_days' => 'many',
        ]);

        self::assertFalse($config->enabled);
        self::assertSame('hosting', $config->platformType);
        self::assertSame(180, $config->appealWindowDays);
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function hostingOrAboveProvider(): iterable
    {
        yield 'intermediary is not hosting or above' => ['intermediary', false];
        yield 'hosting is hosting or above' => ['hosting', true];
        yield 'platform is hosting or above' => ['platform', true];
        yield 'vlop is hosting or above' => ['vlop', true];
    }

    #[Test]
    #[DataProvider('hostingOrAboveProvider')]
    public function isHostingOrAboveReturnsExpectedResult(string $type, bool $expected): void
    {
        $config = new DsaConfig(platformType: $type);

        self::assertSame($expected, $config->isHostingOrAbove());
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function platformOrAboveProvider(): iterable
    {
        yield 'intermediary is not platform or above' => ['intermediary', false];
        yield 'hosting is not platform or above' => ['hosting', false];
        yield 'platform is platform or above' => ['platform', true];
        yield 'vlop is platform or above' => ['vlop', true];
    }

    #[Test]
    #[DataProvider('platformOrAboveProvider')]
    public function isPlatformOrAboveReturnsExpectedResult(string $type, bool $expected): void
    {
        $config = new DsaConfig(platformType: $type);

        self::assertSame($expected, $config->isPlatformOrAbove());
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function vlopProvider(): iterable
    {
        yield 'intermediary is not vlop' => ['intermediary', false];
        yield 'hosting is not vlop' => ['hosting', false];
        yield 'platform is not vlop' => ['platform', false];
        yield 'vlop is vlop' => ['vlop', true];
    }

    #[Test]
    #[DataProvider('vlopProvider')]
    public function isVlopReturnsExpectedResult(string $type, bool $expected): void
    {
        $config = new DsaConfig(platformType: $type);

        self::assertSame($expected, $config->isVlop());
    }
}
