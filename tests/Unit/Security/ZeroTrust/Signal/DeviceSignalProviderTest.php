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
    // ── Name / structure ───────────────────────────────────────────────

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
    public function allClaimsHaveTimestamps(): void
    {
        $provider = new DeviceSignalProvider();
        $claims = $provider->evaluate($this->createContext());

        foreach ($claims as $claim) {
            self::assertGreaterThan(new DateTimeImmutable('-1 minute'), $claim->timestamp);
        }
    }

    // ── No device info ─────────────────────────────────────────────────

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
        self::assertSame(0.1, self::firstClaim($claims, 'device.registered')->confidence);
        self::assertSame(0.1, self::firstClaim($claims, 'device.attestation_valid')->confidence);
    }

    #[Test]
    public function emptyCookieAndEmptyAttributeReturnsNoDeviceInfo(): void
    {
        $provider = new DeviceSignalProvider();
        $context = $this->createContext(cookies: ['_pulsar_device_id' => '']);

        $claims = $provider->evaluate($context);

        self::assertFalse(self::firstClaim($claims, 'device.known')->value);
        self::assertSame(0.1, self::firstClaim($claims, 'device.known')->confidence);
    }

    #[Test]
    public function emptyAttributeAlsoTreatedAsNoDevice(): void
    {
        $provider = new DeviceSignalProvider();
        $context = $this->createContext(attributes: ['device_id' => '']);

        $claims = $provider->evaluate($context);

        self::assertFalse(self::firstClaim($claims, 'device.known')->value);
        self::assertSame(0.1, self::firstClaim($claims, 'device.known')->confidence);
    }

    // ── Cookie present, no registry ────────────────────────────────────

    #[Test]
    public function deviceCookieWithoutRegistryReturnsMediumConfidence(): void
    {
        $provider = new DeviceSignalProvider();
        $context = $this->createContext(cookies: ['_pulsar_device_id' => 'dev-123']);

        $claims = $provider->evaluate($context);

        self::assertTrue(self::firstClaim($claims, 'device.known')->value);
        self::assertFalse(self::firstClaim($claims, 'device.registered')->value);
        self::assertFalse(self::firstClaim($claims, 'device.attestation_valid')->value);
        self::assertSame(0.7, self::firstClaim($claims, 'device.known')->confidence);
        self::assertSame(0.7, self::firstClaim($claims, 'device.registered')->confidence);
        // attestation_valid gets NO_DEVICE_INFO confidence when not registered
        self::assertSame(0.1, self::firstClaim($claims, 'device.attestation_valid')->confidence);
    }

    // ── Cookie + registry: registered device ───────────────────────────

    #[Test]
    public function registeredDeviceWithVerificationReturnsHighConfidence(): void
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
        self::assertSame(0.95, self::firstClaim($claims, 'device.registered')->confidence);
        self::assertSame(0.95, self::firstClaim($claims, 'device.attestation_valid')->confidence);
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
        // attestation is still at registered confidence since device is registered
        self::assertSame(0.95, self::firstClaim($claims, 'device.attestation_valid')->confidence);
    }

    // ── Cookie + registry: unknown device ──────────────────────────────

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
        self::assertFalse(self::firstClaim($claims, 'device.attestation_valid')->value);
        self::assertSame(0.7, self::firstClaim($claims, 'device.known')->confidence);
    }

    // ── Fallback to device_id attribute ────────────────────────────────

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
    public function cookieTakesPrecedenceOverAttribute(): void
    {
        $cookieDevice = new DeviceIdentity(
            deviceId: 'dev-cookie',
            identityId: 'user-1',
            fingerprint: 'fp-1',
            attestationType: 'webauthn',
            publicKey: 'pk-1',
            registeredAt: new DateTimeImmutable(),
            lastVerifiedAt: new DateTimeImmutable(),
        );

        $registry = $this->createMock(DeviceRegistryInterface::class);
        $registry->expects(self::once())
            ->method('find')
            ->with('dev-cookie')
            ->willReturn($cookieDevice);

        $provider = new DeviceSignalProvider($registry);
        $context = $this->createContext(
            cookies: ['_pulsar_device_id' => 'dev-cookie'],
            attributes: ['device_id' => 'dev-attr'],
        );

        $claims = $provider->evaluate($context);

        self::assertTrue(self::firstClaim($claims, 'device.known')->value);
        self::assertTrue(self::firstClaim($claims, 'device.registered')->value);
    }

    #[Test]
    public function emptyCookieFallsBackToAttribute(): void
    {
        $device = new DeviceIdentity(
            deviceId: 'dev-attr-fb',
            identityId: 'user-1',
            fingerprint: 'fp-fb',
            attestationType: 'webauthn',
            publicKey: 'pk-fb',
            registeredAt: new DateTimeImmutable(),
            lastVerifiedAt: new DateTimeImmutable(),
        );

        $registry = $this->createStub(DeviceRegistryInterface::class);
        $registry->method('find')->willReturn($device);

        $provider = new DeviceSignalProvider($registry);
        $context = $this->createContext(
            cookies: ['_pulsar_device_id' => ''],
            attributes: ['device_id' => 'dev-attr-fb'],
        );

        $claims = $provider->evaluate($context);

        self::assertTrue(self::firstClaim($claims, 'device.known')->value);
    }

    #[Test]
    public function noCookieAndNullAttributeReturnsNoDevice(): void
    {
        $provider = new DeviceSignalProvider();
        $context = $this->createContext(attributes: ['device_id' => null]);

        $claims = $provider->evaluate($context);

        self::assertFalse(self::firstClaim($claims, 'device.known')->value);
    }

    // ── Confidence resolution ──────────────────────────────────────────

    #[Test]
    public function confidenceScaleIsCorrect(): void
    {
        // No cookie, no registration -> 0.1
        $providerNone = new DeviceSignalProvider();
        $claimsNone = $providerNone->evaluate($this->createContext());
        self::assertSame(0.1, self::firstClaim($claimsNone, 'device.known')->confidence);

        // Cookie only -> 0.7
        $providerCookie = new DeviceSignalProvider();
        $claimsCookie = $providerCookie->evaluate(
            $this->createContext(cookies: ['_pulsar_device_id' => 'dev-1']),
        );
        self::assertSame(0.7, self::firstClaim($claimsCookie, 'device.known')->confidence);

        // Cookie + registered -> 0.95
        $device = new DeviceIdentity(
            deviceId: 'dev-2',
            identityId: 'user-1',
            fingerprint: 'fp',
            attestationType: 'webauthn',
            publicKey: 'pk',
            registeredAt: new DateTimeImmutable(),
            lastVerifiedAt: new DateTimeImmutable(),
        );
        $registry = $this->createStub(DeviceRegistryInterface::class);
        $registry->method('find')->willReturn($device);

        $providerReg = new DeviceSignalProvider($registry);
        $claimsReg = $providerReg->evaluate(
            $this->createContext(cookies: ['_pulsar_device_id' => 'dev-2']),
        );
        self::assertSame(0.95, self::firstClaim($claimsReg, 'device.known')->confidence);
    }

    #[Test]
    public function attestationValidConfidenceDropsToLowWhenNotRegistered(): void
    {
        $provider = new DeviceSignalProvider();
        $context = $this->createContext(cookies: ['_pulsar_device_id' => 'dev-unreg']);

        $claims = $provider->evaluate($context);

        // device.known and device.registered get COOKIE_ONLY confidence
        self::assertSame(0.7, self::firstClaim($claims, 'device.known')->confidence);
        self::assertSame(0.7, self::firstClaim($claims, 'device.registered')->confidence);
        // But attestation_valid gets NO_DEVICE_INFO since not registered
        self::assertSame(0.1, self::firstClaim($claims, 'device.attestation_valid')->confidence);
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
