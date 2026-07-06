<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\AntiSpam\Risk;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\AntiSpam\Risk\Ja4Config;

#[CoversClass(Ja4Config::class)]
final class Ja4ConfigTest extends TestCase
{
    #[Test]
    public function defaultsAreDisabledAndTrustedProxyGated(): void
    {
        $config = new Ja4Config();

        self::assertFalse($config->enabled);
        self::assertSame('X-JA4', $config->headerName);
        self::assertTrue($config->trustedProxiesOnly);
        self::assertSame([], $config->knownBadFingerprints);
        self::assertSame([], $config->knownBadPrefixes);
        self::assertSame([], $config->knownGoodFingerprints);
        self::assertSame(0.9, $config->matchScore);
        self::assertSame(0.6, $config->partialMatchScore);
    }

    #[Test]
    public function fromArrayParsesEveryField(): void
    {
        $config = Ja4Config::fromArray([
            'enabled' => true,
            'header_name' => 'X-Edge-JA4',
            'trusted_proxies_only' => false,
            'known_bad_fingerprints' => ['t13d1516h2_8daaf6152771_b186095e22b6'],
            'known_bad_prefixes' => ['t13d', 'q13'],
            'known_good_fingerprints' => ['t13d_known_good'],
            'match_score' => '0.75',
            'partial_match_score' => '0.4',
        ]);

        self::assertTrue($config->enabled);
        self::assertSame('X-Edge-JA4', $config->headerName);
        self::assertFalse($config->trustedProxiesOnly);
        self::assertSame(['t13d1516h2_8daaf6152771_b186095e22b6'], $config->knownBadFingerprints);
        self::assertSame(['t13d', 'q13'], $config->knownBadPrefixes);
        self::assertSame(['t13d_known_good'], $config->knownGoodFingerprints);
        self::assertSame(0.75, $config->matchScore);
        self::assertSame(0.4, $config->partialMatchScore);
    }

    #[Test]
    public function fromArrayFiltersNonStringPrefixesAndAllowlist(): void
    {
        $config = Ja4Config::fromArray([
            'known_bad_prefixes' => ['t13', 7, null, 'q13'],
            'known_good_fingerprints' => ['safe', false, 'safe2'],
        ]);

        self::assertSame(['t13', 'q13'], $config->knownBadPrefixes);
        self::assertSame(['safe', 'safe2'], $config->knownGoodFingerprints);
    }

    #[Test]
    public function fromArrayFiltersNonStringFingerprintsAndReindexes(): void
    {
        $config = Ja4Config::fromArray([
            'known_bad_fingerprints' => ['good', 42, null, 'also-good', false],
        ]);

        self::assertSame(['good', 'also-good'], $config->knownBadFingerprints);
    }

    #[Test]
    public function fromArrayUsesDefaultsForMissingKeys(): void
    {
        $config = Ja4Config::fromArray([]);

        self::assertFalse($config->enabled);
        self::assertSame('X-JA4', $config->headerName);
        self::assertTrue($config->trustedProxiesOnly);
        self::assertSame([], $config->knownBadFingerprints);
        self::assertSame(0.9, $config->matchScore);
    }

    #[Test]
    public function fromArrayIgnoresNonArrayFingerprintList(): void
    {
        /** @psalm-suppress InvalidArgument intentionally malformed config */
        $config = Ja4Config::fromArray(['known_bad_fingerprints' => 'not-a-list']);

        self::assertSame([], $config->knownBadFingerprints);
    }
}
