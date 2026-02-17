<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Api\Version;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Api\Version\ApiVersion;
use Pulsar\Api\Version\VersionStrategy;

#[CoversClass(VersionStrategy::class)]
#[CoversClass(ApiVersion::class)]
final class VersionValueObjectTest extends TestCase
{
    // ── VersionStrategy ─────────────────────────────────────────────────

    #[Test]
    public function versionStrategyHasThreeCases(): void
    {
        self::assertCount(3, VersionStrategy::cases());
    }

    #[Test]
    #[DataProvider('versionStrategyProvider')]
    public function versionStrategyBackedValues(VersionStrategy $strategy, string $expected): void
    {
        self::assertSame($expected, $strategy->value);
    }

    /**
     * @return iterable<string, array{VersionStrategy, string}>
     */
    public static function versionStrategyProvider(): iterable
    {
        yield 'UrlPrefix' => [VersionStrategy::UrlPrefix, 'url'];
        yield 'Header' => [VersionStrategy::Header, 'header'];
        yield 'QueryParameter' => [VersionStrategy::QueryParameter, 'query'];
    }

    #[Test]
    public function versionStrategyFromBackedValue(): void
    {
        self::assertSame(VersionStrategy::UrlPrefix, VersionStrategy::from('url'));
        self::assertSame(VersionStrategy::QueryParameter, VersionStrategy::from('query'));
    }

    // ── ApiVersion ──────────────────────────────────────────────────────

    #[Test]
    public function apiVersionConstructionWithDefaults(): void
    {
        $v = new ApiVersion(version: '1');

        self::assertSame('1', $v->version);
        self::assertFalse($v->deprecated);
    }

    #[Test]
    public function apiVersionConstructionDeprecated(): void
    {
        $v = new ApiVersion(version: '1', deprecated: true);

        self::assertSame('1', $v->version);
        self::assertTrue($v->deprecated);
    }

    #[Test]
    public function apiVersionFromStringStripsLowercaseV(): void
    {
        $v = ApiVersion::fromString('v2');

        self::assertSame('2', $v->version);
        self::assertFalse($v->deprecated);
    }

    #[Test]
    public function apiVersionFromStringStripsUppercaseV(): void
    {
        $v = ApiVersion::fromString('V3');

        self::assertSame('3', $v->version);
    }

    #[Test]
    public function apiVersionFromStringWithNoPrefix(): void
    {
        $v = ApiVersion::fromString('4');

        self::assertSame('4', $v->version);
    }

    #[Test]
    public function apiVersionFromStringStripsMultipleVPrefixes(): void
    {
        $v = ApiVersion::fromString('vvV1');

        self::assertSame('1', $v->version);
    }

    #[Test]
    public function apiVersionPrefixed(): void
    {
        $v = new ApiVersion(version: '2');

        self::assertSame('v2', $v->prefixed());
    }

    #[Test]
    public function apiVersionFromStringThenPrefixedRoundTrips(): void
    {
        $v = ApiVersion::fromString('v5');

        self::assertSame('v5', $v->prefixed());
    }
}
