<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\ZeroTrust\Signal;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Security\ZeroTrust\Claim\Claim;
use Pulsar\Security\ZeroTrust\Claim\ClaimSet;
use Pulsar\Security\ZeroTrust\Claim\ClaimSource;
use Pulsar\Security\ZeroTrust\Signal\Internal\NetworkSignalProvider;
use Pulsar\Security\ZeroTrust\Signal\NetworkIntelligenceInterface;
use Pulsar\Security\ZeroTrust\Signal\SignalContext;

use function sprintf;

#[CoversClass(NetworkSignalProvider::class)]
final class NetworkSignalProviderTest extends TestCase
{
    // ── Name / structure ───────────────────────────────────────────────

    #[Test]
    public function nameReturnsNetwork(): void
    {
        $provider = new NetworkSignalProvider();

        self::assertSame('network', $provider->name());
    }

    #[Test]
    public function producesThreeClaims(): void
    {
        $provider = new NetworkSignalProvider($this->intelligenceReturning('external', false, false));
        $claims = $provider->evaluate($this->createContext('203.0.113.1'));

        self::assertCount(3, $claims);
        self::assertTrue($claims->has('network.zone'));
        self::assertTrue($claims->has('network.tor_exit'));
        self::assertTrue($claims->has('network.known_proxy'));
    }

    #[Test]
    public function allClaimsHaveNetworkSource(): void
    {
        $provider = new NetworkSignalProvider($this->intelligenceReturning('external', false, false));
        $claims = $provider->evaluate($this->createContext('203.0.113.1'));

        foreach ($claims as $claim) {
            self::assertSame(ClaimSource::NetworkSignal, $claim->source);
        }
    }

    #[Test]
    public function allClaimsHaveTimestamps(): void
    {
        $provider = new NetworkSignalProvider($this->intelligenceReturning('external', false, false));
        $claims = $provider->evaluate($this->createContext('1.2.3.4'));

        foreach ($claims as $claim) {
            self::assertGreaterThan(new \DateTimeImmutable('-1 minute'), $claim->timestamp);
        }
    }

    // ── With intelligence: zone classification ─────────────────────────

    #[Test]
    public function internalNetworkReturnsHighConfidence(): void
    {
        $provider = new NetworkSignalProvider($this->intelligenceReturning('internal', false, false));
        $claims = $provider->evaluate($this->createContext('10.0.0.5'));

        self::assertSame('internal', self::firstClaim($claims, 'network.zone')->value);
        self::assertSame(0.95, self::firstClaim($claims, 'network.zone')->confidence);
    }

    #[Test]
    public function externalNetworkReturnsModerateConfidence(): void
    {
        $provider = new NetworkSignalProvider($this->intelligenceReturning('external', false, false));
        $claims = $provider->evaluate($this->createContext('203.0.113.1'));

        self::assertSame('external', self::firstClaim($claims, 'network.zone')->value);
        self::assertSame(0.7, self::firstClaim($claims, 'network.zone')->confidence);
    }

    #[Test]
    public function vpnZoneClassification(): void
    {
        $provider = new NetworkSignalProvider($this->intelligenceReturning('vpn', false, false));
        $claims = $provider->evaluate($this->createContext('10.8.0.1'));

        self::assertSame('vpn', self::firstClaim($claims, 'network.zone')->value);
        // vpn is neither "internal" nor tor/proxy, so gets CONFIDENCE_EXTERNAL
        self::assertSame(0.7, self::firstClaim($claims, 'network.zone')->confidence);
    }

    // ── With intelligence: threat signals ──────────────────────────────

    #[Test]
    public function detectsTorExitNode(): void
    {
        $provider = new NetworkSignalProvider($this->intelligenceReturning('external', true, false));
        $claims = $provider->evaluate($this->createContext('198.51.100.1'));

        self::assertTrue(self::firstClaim($claims, 'network.tor_exit')->value);
        self::assertSame(0.85, self::firstClaim($claims, 'network.tor_exit')->confidence);
    }

    #[Test]
    public function detectsKnownProxy(): void
    {
        $provider = new NetworkSignalProvider($this->intelligenceReturning('external', false, true));
        $claims = $provider->evaluate($this->createContext('198.51.100.2'));

        self::assertTrue(self::firstClaim($claims, 'network.known_proxy')->value);
        self::assertSame(0.85, self::firstClaim($claims, 'network.known_proxy')->confidence);
    }

    #[Test]
    public function torExitAndKnownProxyBothDetectedSimultaneously(): void
    {
        $provider = new NetworkSignalProvider($this->intelligenceReturning('external', true, true));
        $claims = $provider->evaluate($this->createContext('198.51.100.3'));

        self::assertTrue(self::firstClaim($claims, 'network.tor_exit')->value);
        self::assertTrue(self::firstClaim($claims, 'network.known_proxy')->value);
        self::assertSame(0.85, self::firstClaim($claims, 'network.tor_exit')->confidence);
        self::assertSame(0.85, self::firstClaim($claims, 'network.known_proxy')->confidence);
        // Zone confidence also uses CONFIDENCE_VERIFIED_PROXY when tor/proxy is true
        self::assertSame(0.85, self::firstClaim($claims, 'network.zone')->confidence);
    }

    #[Test]
    public function cleanExternalIpHasLowerProxyConfidence(): void
    {
        $provider = new NetworkSignalProvider($this->intelligenceReturning('external', false, false));
        $claims = $provider->evaluate($this->createContext('203.0.113.1'));

        self::assertFalse(self::firstClaim($claims, 'network.tor_exit')->value);
        self::assertFalse(self::firstClaim($claims, 'network.known_proxy')->value);
        self::assertSame(0.7, self::firstClaim($claims, 'network.tor_exit')->confidence);
        self::assertSame(0.7, self::firstClaim($claims, 'network.known_proxy')->confidence);
    }

    #[Test]
    public function torExitOnInternalZoneKeepsInternalConfidence(): void
    {
        // Edge case: intelligence says internal zone but also tor exit
        $provider = new NetworkSignalProvider($this->intelligenceReturning('internal', true, false));
        $claims = $provider->evaluate($this->createContext('10.0.0.1'));

        // Internal zone takes priority for zone confidence
        self::assertSame(0.95, self::firstClaim($claims, 'network.zone')->confidence);
        // Tor exit claim gets its own CONFIDENCE_VERIFIED_PROXY because value is true
        self::assertSame(0.85, self::firstClaim($claims, 'network.tor_exit')->confidence);
    }

    // ── IP extraction ──────────────────────────────────────────────────

    #[Test]
    public function extractsIpFromServerParams(): void
    {
        $intelligence = $this->createMock(NetworkIntelligenceInterface::class);
        $intelligence->expects(self::once())->method('resolveZone')->with('8.8.8.8')->willReturn('external');
        $intelligence->method('isTorExitNode')->willReturn(false);
        $intelligence->method('isKnownProxy')->willReturn(false);

        $provider = new NetworkSignalProvider($intelligence);
        $provider->evaluate($this->createContext('8.8.8.8'));
    }

    #[Test]
    public function defaultsToLocalhostWhenNoRemoteAddr(): void
    {
        $intelligence = $this->createMock(NetworkIntelligenceInterface::class);
        $intelligence->expects(self::once())->method('resolveZone')->with('127.0.0.1')->willReturn('internal');
        $intelligence->method('isTorExitNode')->willReturn(false);
        $intelligence->method('isKnownProxy')->willReturn(false);

        $provider = new NetworkSignalProvider($intelligence);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getServerParams')->willReturn([]);

        $context = new SignalContext(request: $request, identityId: 'user-1');
        $provider->evaluate($context);
    }

    #[Test]
    public function defaultsToLocalhostWhenRemoteAddrIsNonString(): void
    {
        $intelligence = $this->createMock(NetworkIntelligenceInterface::class);
        $intelligence->expects(self::once())->method('resolveZone')->with('127.0.0.1')->willReturn('internal');
        $intelligence->method('isTorExitNode')->willReturn(false);
        $intelligence->method('isKnownProxy')->willReturn(false);

        $provider = new NetworkSignalProvider($intelligence);

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getServerParams')->willReturn(['REMOTE_ADDR' => 12345]);

        $context = new SignalContext(request: $request, identityId: 'user-1');
        $provider->evaluate($context);
    }

    // ── Degraded mode (no intelligence) ────────────────────────────────

    #[Test]
    public function noIntelligenceWithPublicIpReturnsDegraded(): void
    {
        $provider = new NetworkSignalProvider();
        $claims = $provider->evaluate($this->createContext('203.0.113.1'));

        self::assertSame('external', self::firstClaim($claims, 'network.zone')->value);
        self::assertSame(0.1, self::firstClaim($claims, 'network.zone')->confidence);
        self::assertFalse(self::firstClaim($claims, 'network.tor_exit')->value);
        self::assertSame(0.1, self::firstClaim($claims, 'network.tor_exit')->confidence);
        self::assertFalse(self::firstClaim($claims, 'network.known_proxy')->value);
        self::assertSame(0.1, self::firstClaim($claims, 'network.known_proxy')->confidence);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function privateIpProvider(): array
    {
        return [
            'loopback 127.0.0.1' => ['127.0.0.1'],
            'loopback 127.255.255.1' => ['127.255.255.1'],
            '10.x.x.x start' => ['10.0.0.1'],
            '10.x.x.x end' => ['10.255.255.1'],
            '192.168.x.x' => ['192.168.1.100'],
            '192.168.0.1' => ['192.168.0.1'],
            '172.16.x.x start' => ['172.16.0.1'],
            '172.31.x.x end' => ['172.31.255.254'],
            'carrier-grade NAT' => ['100.64.0.1'],
            'carrier-grade NAT upper' => ['100.127.255.254'],
        ];
    }

    #[Test]
    #[DataProvider('privateIpProvider')]
    public function noIntelligenceWithPrivateIpDetectsInternal(string $ip): void
    {
        $provider = new NetworkSignalProvider();
        $claims = $provider->evaluate($this->createContext($ip));

        self::assertSame('internal', self::firstClaim($claims, 'network.zone')->value);
        self::assertSame(0.95, self::firstClaim($claims, 'network.zone')->confidence);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function publicIpProvider(): array
    {
        return [
            'Google DNS' => ['8.8.8.8'],
            'Cloudflare DNS' => ['1.1.1.1'],
            'documentation range' => ['203.0.113.50'],
            'just outside 172 private' => ['172.32.0.1'],
            'just outside carrier-grade' => ['100.128.0.1'],
        ];
    }

    #[Test]
    #[DataProvider('publicIpProvider')]
    public function noIntelligenceWithPublicIpDetectsExternal(string $ip): void
    {
        $provider = new NetworkSignalProvider();
        $claims = $provider->evaluate($this->createContext($ip));

        self::assertSame('external', self::firstClaim($claims, 'network.zone')->value);
        self::assertSame(0.1, self::firstClaim($claims, 'network.zone')->confidence);
    }

    #[Test]
    public function noIntelligenceWithInvalidIpDetectsExternal(): void
    {
        $provider = new NetworkSignalProvider();
        $claims = $provider->evaluate($this->createContext('not-an-ip'));

        // ip2long returns false for invalid IPs, so isPrivateIp returns false
        self::assertSame('external', self::firstClaim($claims, 'network.zone')->value);
        self::assertSame(0.1, self::firstClaim($claims, 'network.zone')->confidence);
    }

    #[Test]
    public function noIntelligenceDegradedTorAndProxyAlwaysFalse(): void
    {
        $provider = new NetworkSignalProvider();
        $claims = $provider->evaluate($this->createContext('10.0.0.1'));

        // Without intelligence, tor_exit and known_proxy are always false
        self::assertFalse(self::firstClaim($claims, 'network.tor_exit')->value);
        self::assertFalse(self::firstClaim($claims, 'network.known_proxy')->value);
        // And both use CONFIDENCE_DEGRADED
        self::assertSame(0.1, self::firstClaim($claims, 'network.tor_exit')->confidence);
        self::assertSame(0.1, self::firstClaim($claims, 'network.known_proxy')->confidence);
    }

    #[Test]
    public function degradedModeStillProducesThreeClaims(): void
    {
        $provider = new NetworkSignalProvider();
        $claims = $provider->evaluate($this->createContext('203.0.113.1'));

        self::assertCount(3, $claims);
        self::assertTrue($claims->has('network.zone'));
        self::assertTrue($claims->has('network.tor_exit'));
        self::assertTrue($claims->has('network.known_proxy'));
    }

    private static function firstClaim(ClaimSet $claims, string $name): Claim
    {
        $claim = $claims->first($name);
        self::assertNotNull($claim, sprintf('Expected claim "%s" to exist', $name));

        return $claim;
    }

    private function createContext(string $ip): SignalContext
    {
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getServerParams')->willReturn(['REMOTE_ADDR' => $ip]);

        return new SignalContext(request: $request, identityId: 'user-1');
    }

    private function intelligenceReturning(string $zone, bool $tor, bool $proxy): NetworkIntelligenceInterface
    {
        $intelligence = $this->createStub(NetworkIntelligenceInterface::class);
        $intelligence->method('resolveZone')->willReturn($zone);
        $intelligence->method('isTorExitNode')->willReturn($tor);
        $intelligence->method('isKnownProxy')->willReturn($proxy);

        return $intelligence;
    }
}
