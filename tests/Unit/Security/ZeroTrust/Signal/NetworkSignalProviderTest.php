<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\ZeroTrust\Signal;

use PHPUnit\Framework\Attributes\CoversClass;
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
    public function vpnZoneClassification(): void
    {
        $provider = new NetworkSignalProvider($this->intelligenceReturning('vpn', false, false));
        $claims = $provider->evaluate($this->createContext('10.8.0.1'));

        self::assertSame('vpn', self::firstClaim($claims, 'network.zone')->value);
    }

    #[Test]
    public function noIntelligenceWithPrivateIpDetectsInternal(): void
    {
        $provider = new NetworkSignalProvider();
        $claims = $provider->evaluate($this->createContext('192.168.1.100'));

        self::assertSame('internal', self::firstClaim($claims, 'network.zone')->value);
        self::assertSame(0.95, self::firstClaim($claims, 'network.zone')->confidence);
    }

    #[Test]
    public function noIntelligenceWithPublicIpReturnsDegraded(): void
    {
        $provider = new NetworkSignalProvider();
        $claims = $provider->evaluate($this->createContext('203.0.113.1'));

        self::assertSame('external', self::firstClaim($claims, 'network.zone')->value);
        self::assertSame(0.1, self::firstClaim($claims, 'network.zone')->confidence);
        self::assertFalse(self::firstClaim($claims, 'network.tor_exit')->value);
        self::assertSame(0.1, self::firstClaim($claims, 'network.tor_exit')->confidence);
    }

    #[Test]
    public function noIntelligenceWithLocalhostDetectsInternal(): void
    {
        $provider = new NetworkSignalProvider();
        $claims = $provider->evaluate($this->createContext('127.0.0.1'));

        self::assertSame('internal', self::firstClaim($claims, 'network.zone')->value);
        self::assertSame(0.95, self::firstClaim($claims, 'network.zone')->confidence);
    }

    #[Test]
    public function noIntelligenceWith10NetworkDetectsInternal(): void
    {
        $provider = new NetworkSignalProvider();
        $claims = $provider->evaluate($this->createContext('10.255.255.1'));

        self::assertSame('internal', self::firstClaim($claims, 'network.zone')->value);
    }

    #[Test]
    public function noIntelligenceWith172PrivateNetworkDetectsInternal(): void
    {
        $provider = new NetworkSignalProvider();
        $claims = $provider->evaluate($this->createContext('172.16.0.1'));

        self::assertSame('internal', self::firstClaim($claims, 'network.zone')->value);
    }

    #[Test]
    public function noIntelligenceWithCarrierGradeNatDetectsInternal(): void
    {
        $provider = new NetworkSignalProvider();
        $claims = $provider->evaluate($this->createContext('100.64.0.1'));

        self::assertSame('internal', self::firstClaim($claims, 'network.zone')->value);
    }

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

        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getServerParams')->willReturn([]);

        $context = new SignalContext(request: $request, identityId: 'user-1');
        $provider->evaluate($context);
    }

    private static function firstClaim(ClaimSet $claims, string $name): Claim
    {
        $claim = $claims->first($name);
        self::assertNotNull($claim, sprintf('Expected claim "%s" to exist', $name));

        return $claim;
    }

    private function createContext(string $ip): SignalContext
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getServerParams')->willReturn(['REMOTE_ADDR' => $ip]);

        return new SignalContext(request: $request, identityId: 'user-1');
    }

    private function intelligenceReturning(string $zone, bool $tor, bool $proxy): NetworkIntelligenceInterface
    {
        $intelligence = $this->createMock(NetworkIntelligenceInterface::class);
        $intelligence->method('resolveZone')->willReturn($zone);
        $intelligence->method('isTorExitNode')->willReturn($tor);
        $intelligence->method('isKnownProxy')->willReturn($proxy);

        return $intelligence;
    }
}
