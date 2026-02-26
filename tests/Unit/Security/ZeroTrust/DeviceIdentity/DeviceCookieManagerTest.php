<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Security\ZeroTrust\DeviceIdentity;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Security\Crypto\EnvKeyRing;
use Pulsar\Security\ZeroTrust\DeviceIdentity\Internal\DeviceCookieManager;

use function base64_encode;
use function random_bytes;

#[CoversClass(DeviceCookieManager::class)]
final class DeviceCookieManagerTest extends TestCase
{
    private DeviceCookieManager $manager;
    private string $key;

    protected function setUp(): void
    {
        $this->key = random_bytes(32);
        $keyRing = new EnvKeyRing(['device-cookie' => $this->key]);
        $this->manager = new DeviceCookieManager($keyRing);
    }

    #[Test]
    public function createReturnsCookieValue(): void
    {
        $cookie = $this->manager->create('device-abc');

        self::assertNotNull($cookie);
        self::assertNotEmpty($cookie);
    }

    #[Test]
    public function verifyReturnsDeviceIdForValidCookie(): void
    {
        $cookie = $this->manager->create('device-abc');
        self::assertNotNull($cookie);

        $deviceId = $this->manager->verify($cookie);

        self::assertSame('device-abc', $deviceId);
    }

    #[Test]
    public function verifyReturnsNullForTamperedCookie(): void
    {
        $cookie = $this->manager->create('device-abc');
        self::assertNotNull($cookie);

        // Tamper with the cookie by modifying a character
        $tampered = $cookie . 'x';
        $deviceId = $this->manager->verify($tampered);

        self::assertNull($deviceId);
    }

    #[Test]
    public function verifyReturnsNullForExpiredCookie(): void
    {
        // Create manager with 0-second lifetime to get an immediately expired cookie
        $keyRing = new EnvKeyRing(['device-cookie' => $this->key]);
        $manager = new DeviceCookieManager($keyRing, lifetime: -1);

        $cookie = $manager->create('device-abc');
        self::assertNotNull($cookie);

        $deviceId = $manager->verify($cookie);

        self::assertNull($deviceId);
    }

    #[Test]
    public function verifyReturnsNullForInvalidBase64(): void
    {
        self::assertNull($this->manager->verify('not-valid-base64!!!'));
    }

    #[Test]
    public function verifyReturnsNullForMalformedPayload(): void
    {
        // Only two parts instead of three
        $malformed = base64_encode('device-abc|12345');

        self::assertNull($this->manager->verify($malformed));
    }

    #[Test]
    public function verifyReturnsNullForWrongKey(): void
    {
        $cookie = $this->manager->create('device-abc');
        self::assertNotNull($cookie);

        // Create a new manager with a different key
        $differentKey = random_bytes(32);
        $differentKeyRing = new EnvKeyRing(['device-cookie' => $differentKey]);
        $differentManager = new DeviceCookieManager($differentKeyRing);

        self::assertNull($differentManager->verify($cookie));
    }

    #[Test]
    public function cookieNameReturnsConfiguredName(): void
    {
        self::assertSame('__pulsar_device', $this->manager->cookieName());

        $keyRing = new EnvKeyRing(['device-cookie' => $this->key]);
        $custom = new DeviceCookieManager($keyRing, cookieName: '__my_device');
        self::assertSame('__my_device', $custom->cookieName());
    }

    #[Test]
    public function cookieAttributesReturnsSecureDefaults(): void
    {
        $attrs = $this->manager->cookieAttributes();

        self::assertTrue($attrs['secure']);
        self::assertTrue($attrs['httpOnly']);
        self::assertSame('Lax', $attrs['sameSite']);
        self::assertSame('/', $attrs['path']);
        self::assertSame(30 * 24 * 3600, $attrs['maxAge']);
    }

    #[Test]
    public function createReturnsNullWhenKeyUnavailable(): void
    {
        $keyRing = new EnvKeyRing([]);
        $manager = new DeviceCookieManager($keyRing);

        self::assertNull($manager->create('device-abc'));
    }

    #[Test]
    public function verifyReturnsNullWhenKeyUnavailable(): void
    {
        $cookie = $this->manager->create('device-abc');
        self::assertNotNull($cookie);

        $keyRing = new EnvKeyRing([]);
        $manager = new DeviceCookieManager($keyRing);

        self::assertNull($manager->verify($cookie));
    }
}
