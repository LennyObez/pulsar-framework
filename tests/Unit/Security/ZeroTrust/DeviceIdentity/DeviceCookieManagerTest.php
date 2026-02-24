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
use function str_repeat;
use function time;

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

    // ── Cookie creation ────────────────────────────────────────────────

    #[Test]
    public function createReturnsCookieValue(): void
    {
        $cookie = $this->manager->create('device-abc');

        self::assertNotNull($cookie);
        self::assertNotEmpty($cookie);
    }

    #[Test]
    public function createProducesBase64EncodedValue(): void
    {
        $cookie = $this->manager->create('device-abc');
        self::assertNotNull($cookie);

        // base64_decode with strict=true should work
        $decoded = base64_decode($cookie, true);
        self::assertNotFalse($decoded);
    }

    #[Test]
    public function createProducesDifferentCookiesForDifferentDevices(): void
    {
        $c1 = $this->manager->create('device-1');
        $c2 = $this->manager->create('device-2');

        self::assertNotNull($c1);
        self::assertNotNull($c2);
        self::assertNotSame($c1, $c2);
    }

    #[Test]
    public function createReturnsNullWhenKeyUnavailable(): void
    {
        $keyRing = new EnvKeyRing([]);
        $manager = new DeviceCookieManager($keyRing);

        self::assertNull($manager->create('device-abc'));
    }

    // ── Cookie verification ────────────────────────────────────────────

    #[Test]
    public function verifyReturnsDeviceIdForValidCookie(): void
    {
        $cookie = $this->manager->create('device-abc');
        self::assertNotNull($cookie);

        $deviceId = $this->manager->verify($cookie);

        self::assertSame('device-abc', $deviceId);
    }

    #[Test]
    public function verifyRoundTripWithVariousDeviceIds(): void
    {
        $ids = ['dev-1', 'long-device-id-with-many-chars-' . str_repeat('x', 100), 'a', '123'];

        foreach ($ids as $id) {
            $cookie = $this->manager->create($id);
            self::assertNotNull($cookie, "Failed to create cookie for device ID: {$id}");
            self::assertSame($id, $this->manager->verify($cookie), "Round-trip failed for device ID: {$id}");
        }
    }

    // ── Tamper detection ───────────────────────────────────────────────

    #[Test]
    public function verifyReturnsNullForTamperedCookie(): void
    {
        $cookie = $this->manager->create('device-abc');
        self::assertNotNull($cookie);

        // Append extra characters
        $tampered = $cookie . 'x';
        self::assertNull($this->manager->verify($tampered));
    }

    #[Test]
    public function verifyReturnsNullForTamperedDeviceIdInPayload(): void
    {
        // Manually forge a cookie with a different device ID but same HMAC
        $forged = base64_encode('evil-device|' . ((string) (time() + 86400)) . '|fakhmac');

        self::assertNull($this->manager->verify($forged));
    }

    #[Test]
    public function verifyReturnsNullForTamperedExpiry(): void
    {
        // Create valid cookie, decode, modify expiry, re-encode
        $cookie = $this->manager->create('device-abc');
        self::assertNotNull($cookie);

        $decoded = base64_decode($cookie, true);
        self::assertNotFalse($decoded);

        $parts = explode('|', $decoded);
        // Modify expiry to extend it
        $parts[1] = (string) (time() + 999999);
        $forged = base64_encode(implode('|', $parts));

        self::assertNull($this->manager->verify($forged));
    }

    #[Test]
    public function verifyReturnsNullForWrongKey(): void
    {
        $cookie = $this->manager->create('device-abc');
        self::assertNotNull($cookie);

        $differentKey = random_bytes(32);
        $differentKeyRing = new EnvKeyRing(['device-cookie' => $differentKey]);
        $differentManager = new DeviceCookieManager($differentKeyRing);

        self::assertNull($differentManager->verify($cookie));
    }

    // ── Expiry handling ────────────────────────────────────────────────

    #[Test]
    public function verifyReturnsNullForExpiredCookie(): void
    {
        // Create manager with negative lifetime for immediately expired cookie
        $keyRing = new EnvKeyRing(['device-cookie' => $this->key]);
        $manager = new DeviceCookieManager($keyRing, lifetime: -1);

        $cookie = $manager->create('device-abc');
        self::assertNotNull($cookie);

        $deviceId = $manager->verify($cookie);

        self::assertNull($deviceId);
    }

    #[Test]
    public function verifySucceedsForNonExpiredCookie(): void
    {
        // Default lifetime is 30 days, so fresh cookie should verify
        $cookie = $this->manager->create('device-xyz');
        self::assertNotNull($cookie);

        self::assertSame('device-xyz', $this->manager->verify($cookie));
    }

    #[Test]
    public function customLifetimeIsRespected(): void
    {
        $keyRing = new EnvKeyRing(['device-cookie' => $this->key]);
        $manager = new DeviceCookieManager($keyRing, lifetime: 3600); // 1 hour

        $cookie = $manager->create('device-1h');
        self::assertNotNull($cookie);

        // Should still be valid
        self::assertSame('device-1h', $manager->verify($cookie));
    }

    // ── Invalid input handling ─────────────────────────────────────────

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
    public function verifyReturnsNullForSinglePartPayload(): void
    {
        $malformed = base64_encode('just-one-part');

        self::assertNull($this->manager->verify($malformed));
    }

    #[Test]
    public function verifyReturnsNullForFourPartPayload(): void
    {
        $malformed = base64_encode('a|b|c|d');

        self::assertNull($this->manager->verify($malformed));
    }

    #[Test]
    public function verifyReturnsNullForEmptyString(): void
    {
        self::assertNull($this->manager->verify(''));
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

    // ── Cookie name ────────────────────────────────────────────────────

    #[Test]
    public function cookieNameReturnsDefaultName(): void
    {
        self::assertSame('__pulsar_device', $this->manager->cookieName());
    }

    #[Test]
    public function cookieNameReturnsCustomName(): void
    {
        $keyRing = new EnvKeyRing(['device-cookie' => $this->key]);
        $custom = new DeviceCookieManager($keyRing, cookieName: '__my_device');

        self::assertSame('__my_device', $custom->cookieName());
    }

    // ── Cookie attributes ──────────────────────────────────────────────

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
    public function cookieAttributesReflectsCustomConfiguration(): void
    {
        $keyRing = new EnvKeyRing(['device-cookie' => $this->key]);
        $manager = new DeviceCookieManager(
            keyRing: $keyRing,
            lifetime: 7200,
            secure: false,
            httpOnly: false,
            sameSite: 'Strict',
            path: '/app',
        );

        $attrs = $manager->cookieAttributes();

        self::assertFalse($attrs['secure']);
        self::assertFalse($attrs['httpOnly']);
        self::assertSame('Strict', $attrs['sameSite']);
        self::assertSame('/app', $attrs['path']);
        self::assertSame(7200, $attrs['maxAge']);
    }

    #[Test]
    public function cookieAttributesReturnsFiveKeys(): void
    {
        $attrs = $this->manager->cookieAttributes();

        self::assertCount(5, $attrs);
        self::assertArrayHasKey('secure', $attrs);
        self::assertArrayHasKey('httpOnly', $attrs);
        self::assertArrayHasKey('sameSite', $attrs);
        self::assertArrayHasKey('path', $attrs);
        self::assertArrayHasKey('maxAge', $attrs);
    }

    // ── Adversarial edge cases ─────────────────────────────────────────

    #[Test]
    public function cookieWithPipeInDeviceIdStillWorks(): void
    {
        // Note: if device ID contains |, the verify will see more than 3 parts
        // and reject — this is by design as pipe is the separator
        $cookie = $this->manager->create('device|with|pipes');
        self::assertNotNull($cookie);

        // This should fail verification because the decoded payload will have 5 parts
        self::assertNull($this->manager->verify($cookie));
    }

    #[Test]
    public function cookieWithEmptyDeviceIdCreatesValidCookie(): void
    {
        $cookie = $this->manager->create('');
        self::assertNotNull($cookie);

        // Empty device ID round-trips correctly
        self::assertSame('', $this->manager->verify($cookie));
    }

    #[Test]
    public function verifyRejectsBaselineReplayWithDifferentTimestamp(): void
    {
        // Attacker creates a valid cookie, decodes it, replaces expiry but not HMAC
        $cookie = $this->manager->create('device-abc');
        self::assertNotNull($cookie);

        $decoded = base64_decode($cookie, true);
        self::assertNotFalse($decoded);

        $parts = explode('|', $decoded);
        self::assertCount(3, $parts);

        // Extend expiry dramatically
        $parts[1] = (string) (time() + 99999999);
        $tampered = base64_encode($parts[0] . '|' . $parts[1] . '|' . $parts[2]);

        // HMAC no longer matches because payload changed
        self::assertNull($this->manager->verify($tampered));
    }
}
