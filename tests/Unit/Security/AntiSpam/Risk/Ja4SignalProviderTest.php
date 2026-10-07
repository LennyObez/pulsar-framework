<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\AntiSpam\Risk;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Http\TrustedProxy;
use Pulsar\Security\AntiSpam\Risk\Ja4Config;
use Pulsar\Security\AntiSpam\Risk\Ja4SignalProvider;

#[CoversClass(Ja4SignalProvider::class)]
final class Ja4SignalProviderTest extends TestCase
{
    private const string BAD = 't13d1516h2_8daaf6152771_b186095e22b6';

    #[Test]
    public function matchFromTrustedProxyScoresMatchScore(): void
    {
        $provider = new Ja4SignalProvider(
            new Ja4Config(enabled: true, knownBadFingerprints: [self::BAD], matchScore: 0.9),
            new TrustedProxy(['10.0.0.0/8']),
        );

        $signal = $provider->evaluate($this->request(self::BAD, '10.0.0.5'));

        self::assertSame(0.9, $signal->score);
        self::assertSame('ja4', $signal->source);
    }

    #[Test]
    public function spoofedFingerprintFromUntrustedSourceScoresZero(): void
    {
        $provider = new Ja4SignalProvider(
            new Ja4Config(enabled: true, knownBadFingerprints: [self::BAD]),
            new TrustedProxy(['10.0.0.0/8']),
        );

        // Same malicious fingerprint, but the connection is NOT a trusted proxy:
        // a direct client could forge the header, so it must be ignored.
        $signal = $provider->evaluate($this->request(self::BAD, '203.0.113.7'));

        self::assertSame(0.0, $signal->score);
    }

    #[Test]
    public function missingTrustedProxyDependencyScoresZero(): void
    {
        $provider = new Ja4SignalProvider(
            new Ja4Config(enabled: true, knownBadFingerprints: [self::BAD]),
            null,
        );

        $signal = $provider->evaluate($this->request(self::BAD, '10.0.0.5'));

        self::assertSame(0.0, $signal->score);
    }

    #[Test]
    public function unknownFingerprintFromTrustedProxyScoresZero(): void
    {
        $provider = new Ja4SignalProvider(
            new Ja4Config(enabled: true, knownBadFingerprints: [self::BAD]),
            new TrustedProxy(['10.0.0.0/8']),
        );

        $signal = $provider->evaluate($this->request('t13d0000h0_0000_0000', '10.0.0.5'));

        self::assertSame(0.0, $signal->score);
    }

    #[Test]
    public function absentHeaderScoresZero(): void
    {
        $provider = new Ja4SignalProvider(
            new Ja4Config(enabled: true, knownBadFingerprints: [self::BAD]),
            new TrustedProxy(['10.0.0.0/8']),
        );

        $request = new ServerRequest(method: 'GET', uri: '/', serverParams: ['REMOTE_ADDR' => '10.0.0.5']);

        self::assertSame(0.0, $provider->evaluate($request)->score);
    }

    #[Test]
    public function gateDisabledHonoursHeaderWithoutTrustedProxy(): void
    {
        $provider = new Ja4SignalProvider(
            new Ja4Config(enabled: true, trustedProxiesOnly: false, knownBadFingerprints: [self::BAD], matchScore: 0.8),
            null,
        );

        $signal = $provider->evaluate($this->request(self::BAD, '203.0.113.7'));

        self::assertSame(0.8, $signal->score);
    }

    #[Test]
    public function honoursConfiguredHeaderName(): void
    {
        $provider = new Ja4SignalProvider(
            new Ja4Config(enabled: true, headerName: 'X-Edge-JA4', trustedProxiesOnly: false, knownBadFingerprints: [self::BAD]),
            null,
        );

        $request = new ServerRequest(
            method: 'GET',
            uri: '/',
            headers: ['X-Edge-JA4' => self::BAD],
        );

        self::assertSame(0.9, $provider->evaluate($request)->score);
    }

    #[Test]
    public function partialPrefixMatchScoresPartialMatchScore(): void
    {
        $provider = new Ja4SignalProvider(
            new Ja4Config(
                enabled: true,
                trustedProxiesOnly: false,
                knownBadPrefixes: ['t13d1516'],
                partialMatchScore: 0.6,
            ),
            null,
        );

        // BAD starts with the bad prefix but is not on the exact denylist.
        $signal = $provider->evaluate($this->request(self::BAD, '203.0.113.7'));

        self::assertSame(0.6, $signal->score, 'a family/prefix match scores the partial score');
    }

    #[Test]
    public function nonMatchingPrefixScoresZero(): void
    {
        $provider = new Ja4SignalProvider(
            new Ja4Config(enabled: true, trustedProxiesOnly: false, knownBadPrefixes: ['q99x']),
            null,
        );

        self::assertSame(0.0, $provider->evaluate($this->request(self::BAD, '203.0.113.7'))->score);
    }

    #[Test]
    public function allowlistOverridesPrefixDenylist(): void
    {
        $provider = new Ja4SignalProvider(
            new Ja4Config(
                enabled: true,
                trustedProxiesOnly: false,
                knownBadPrefixes: ['t13d1516'],
                knownGoodFingerprints: [self::BAD],
                partialMatchScore: 0.6,
            ),
            null,
        );

        // BAD matches the bad prefix, but the exact allowlist entry wins.
        self::assertSame(0.0, $provider->evaluate($this->request(self::BAD, '203.0.113.7'))->score);
    }

    #[Test]
    public function exactDenylistTakesPrecedenceOverPrefix(): void
    {
        $provider = new Ja4SignalProvider(
            new Ja4Config(
                enabled: true,
                trustedProxiesOnly: false,
                knownBadFingerprints: [self::BAD],
                knownBadPrefixes: ['t13d1516'],
                matchScore: 0.9,
                partialMatchScore: 0.6,
            ),
            null,
        );

        self::assertSame(0.9, $provider->evaluate($this->request(self::BAD, '203.0.113.7'))->score);
    }

    #[Test]
    public function allowlistedFingerprintScoresZero(): void
    {
        $provider = new Ja4SignalProvider(
            new Ja4Config(enabled: true, trustedProxiesOnly: false, knownGoodFingerprints: [self::BAD]),
            null,
        );

        self::assertSame(0.0, $provider->evaluate($this->request(self::BAD, '203.0.113.7'))->score);
    }

    private function request(string $fingerprint, string $remoteAddr): ServerRequest
    {
        return new ServerRequest(
            method: 'GET',
            uri: '/',
            headers: ['X-JA4' => $fingerprint],
            serverParams: ['REMOTE_ADDR' => $remoteAddr],
        );
    }
}
