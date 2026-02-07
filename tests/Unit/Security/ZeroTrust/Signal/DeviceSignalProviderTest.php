<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\ZeroTrust\Signal;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Security\ZeroTrust\Claim\Claim;
use Pulsar\Security\ZeroTrust\Claim\ClaimSet;
use Pulsar\Security\ZeroTrust\Claim\ClaimSource;
use Pulsar\Security\ZeroTrust\DeviceIdentity\DeviceIdentity;
use Pulsar\Security\ZeroTrust\DeviceIdentity\DeviceRegistryInterface;
use Pulsar\Security\ZeroTrust\Signal\Internal\DeviceSignalProvider;
use Pulsar\Security\ZeroTrust\Signal\SignalContext;

use function sprintf;

#[CoversClass(DeviceSignalProvider::class)]
final class DeviceSignalProviderTest extends TestCase
{
    #[Test]
    public function nameReturnsDevice(): void
    {
        $provider = new DeviceSignalProvider();

        self::assertSame('device', $provider->name());
    }

    #[Test]
    public function producesThreeClaimsAlways(): void
    {
        $provider = new DeviceSignalProvider();
        $context = $this->createContext();

        $claims = $provider->evaluate($context);

        self::assertCount(3, $claims);
        self::assertTrue($claims->has('device.known'));
        self::assertTrue($claims->has('device.registered'));
        self::assertTrue($claims->has('device.attestation_valid'));
    }

    #[Test]
    public function allClaimsHaveDeviceSignalSource(): void
    {
        $provider = new DeviceSignalProvider();
        $claims = $provider->evaluate($this->createContext());

        foreach ($claims as $claim) {
            self::assertSame(ClaimSource::DeviceSignal, $claim->source);
        }
    }

    #[Test]
    public function noDeviceCookieReturnsLowConfidence(): void
    {
        $provider = new DeviceSignalProvider();
        $context = $this->createContext();

        $claims = $provider->evaluate($context);

        self::assertFalse(self::firstClaim($claims, 'device.known')->value);
        self::assertFalse(self::firstClaim($claims, 'device.registered')->value);
        self::assertFalse(self::firstClaim($claims, 'device.attestation_valid')->value);
        self::assertSame(0.1, self::firstClaim($claims, 'device.known')->confidence);
    }

    #[Test]
    public function deviceCookieWithoutRegistryReturnsMediumConfidence(): void
    {
        $provider = new DeviceSignalProvider();
        $context = $this->createContext(cookies: ['_pulsar_device_id' => 'dev-123']);

        $claims = $provider->evaluate($context);

        self::assertTrue(self::firstClaim($claims, 'device.known')->value);
        self::assertFalse(self::firstClaim($claims, 'device.registered')->value);
        self::assertSame(0.7, self::firstClaim($claims, 'device.known')->confidence);
    }

    #[Test]
    public function registeredDeviceReturnsHighConfidence(): void
    {
        $device = new DeviceIdentity(
            deviceId: 'dev-123',
            identityId: 'user-1',
            fingerprint: 'fp-abc',
            attestationType: 'webauthn',
            publicKey: 'pk-xyz',
            registeredAt: new DateTimeImmutable('-30 days'),
            lastVerifiedAt: new DateTimeImmutable('-1 hour'),
        );

        $registry = $this->createStub(DeviceRegistryInterface::class);
        $registry->method('find')->willReturn($device);

        $provider = new DeviceSignalProvider($registry);
        $context = $this->createContext(cookies: ['_pulsar_device_id' => 'dev-123']);

        $claims = $provider->evaluate($context);

        self::assertTrue(self::firstClaim($claims, 'device.known')->value);
        self::assertTrue(self::firstClaim($claims, 'device.registered')->value);
        self::assertTrue(self::firstClaim($claims, 'device.attestation_valid')->value);
        self::assertSame(0.95, self::firstClaim($claims, 'device.known')->confidence);
    }

    #[Test]
    public function registeredDeviceWithoutVerificationFlagsAttestationFalse(): void
    {
        $device = new DeviceIdentity(
            deviceId: 'dev-456',
            identityId: 'user-1',
            fingerprint: 'fp-def',
            attestationType: 'client_cert',
            publicKey: 'pk-uvw',
            registeredAt: new DateTimeImmutable('-7 days'),
            lastVerifiedAt: null,
        );

        $registry = $this->createStub(DeviceRegistryInterface::class);
        $registry->method('find')->willReturn($device);

        $provider = new DeviceSignalProvider($registry);
        $context = $this->createContext(cookies: ['_pulsar_device_id' => 'dev-456']);

        $claims = $provider->evaluate($context);

        self::assertTrue(self::firstClaim($claims, 'device.registered')->value);
        self::assertFalse(self::firstClaim($claims, 'device.attestation_valid')->value);
    }

    #[Test]
    public function unknownDeviceInRegistryDegradesCookie(): void
    {
        $registry = $this->createStub(DeviceRegistryInterface::class);
        $registry->method('find')->willReturn(null);

        $provider = new DeviceSignalProvider($registry);
        $context = $this->createContext(cookies: ['_pulsar_device_id' => 'dev-unknown']);

        $claims = $provider->evaluate($context);

        self::assertTrue(self::firstClaim($claims, 'device.known')->value);
        self::assertFalse(self::firstClaim($claims, 'device.registered')->value);
        self::assertSame(0.7, self::firstClaim($claims, 'device.known')->confidence);
    }

    #[Test]
    public function fallsBackToDeviceIdAttribute(): void
    {
        $device = new DeviceIdentity(
            deviceId: 'dev-attr',
            identityId: 'user-1',
            fingerprint: 'fp-ghi',
            attestationType: 'webauthn',
            publicKey: 'pk-rst',
            registeredAt: new DateTimeImmutable(),
            lastVerifiedAt: new DateTimeImmutable(),
        );

        $registry = $this->createStub(DeviceRegistryInterface::class);
        $registry->method('find')->willReturn($device);

        $provider = new DeviceSignalProvider($registry);
        $context = $this->createContext(attributes: ['device_id' => 'dev-attr']);

        $claims = $provider->evaluate($context);

        self::assertTrue(self::firstClaim($claims, 'device.known')->value);
        self::assertTrue(self::firstClaim($claims, 'device.registered')->value);
    }

    #[Test]
    public function emptyCookieAndAttributeReturnsNoDeviceInfo(): void
    {
        $provider = new DeviceSignalProvider();
        $context = $this->createContext(cookies: ['_pulsar_device_id' => '']);

        $claims = $provider->evaluate($context);

        self::assertFalse(self::firstClaim($claims, 'device.known')->value);
        self::assertSame(0.1, self::firstClaim($claims, 'device.known')->confidence);
    }

    private static function firstClaim(ClaimSet $claims, string $name): Claim
    {
        $claim = $claims->first($name);
        self::assertNotNull($claim, sprintf('Expected claim "%s" to exist', $name));

        return $claim;
    }

    /**
     * @param array<string, string> $cookies
     * @param array<string, mixed> $attributes
     */
    private function createContext(array $cookies = [], array $attributes = []): SignalContext
    {
        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getCookieParams')->willReturn($cookies);

        return new SignalContext(
            request: $request,
            identityId: 'user-1',
            attributes: $attributes,
        );
    }
}
