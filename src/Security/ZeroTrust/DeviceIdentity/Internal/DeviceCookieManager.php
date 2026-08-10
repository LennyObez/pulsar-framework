<?php

declare(strict_types=1);

namespace Pulsar\Security\ZeroTrust\DeviceIdentity\Internal;

use Pulsar\Api\Internal;
use Pulsar\Security\Crypto\Hmac;
use Pulsar\Security\Crypto\KeyRingInterface;

use function base64_decode;
use function base64_encode;
use function count;
use function explode;
use function hash_equals;
use function time;

/**
 * Manages secure device cookies with HMAC integrity protection.
 *
 * Cookie format: base64(deviceId|expiry|hmac)
 * The HMAC covers both deviceId and expiry to prevent tampering with either field.
 * All signing operations go through KeyRingInterface.
 */
#[Internal]
final readonly class DeviceCookieManager
{
    private const string SEPARATOR = '|';

    public function __construct(
        private KeyRingInterface $keyRing,
        private string $keyId = 'device-cookie',
        private string $cookieName = '__pulsar_device',
        private int $lifetime = 30 * 24 * 3600, // 30 days
        private bool $secure = true,
        private bool $httpOnly = true,
        private string $sameSite = 'Lax',
        private string $path = '/',
    ) {}

    /**
     * Create a signed device cookie value.
     *
     * @return string|null The base64-encoded cookie value, or null if signing key is unavailable
     */
    public function create(string $deviceId): ?string
    {
        $key = $this->keyRing->keyFor($this->keyId);

        if ($key === null) {
            return null;
        }

        $expiry = (string) (time() + $this->lifetime);
        $payload = $deviceId . self::SEPARATOR . $expiry;
        $hmac = Hmac::computeHex($payload, $key);

        return base64_encode($payload . self::SEPARATOR . $hmac);
    }

    /**
     * Verify a device cookie and extract the device ID.
     *
     * Returns the device ID if the cookie is valid and not expired, null otherwise.
     */
    public function verify(string $cookieValue): ?string
    {
        $key = $this->keyRing->keyFor($this->keyId);

        if ($key === null) {
            return null;
        }

        $decoded = base64_decode($cookieValue, true);

        if ($decoded === false) {
            return null;
        }

        $parts = explode(self::SEPARATOR, $decoded);

        if (count($parts) !== 3) {
            return null;
        }

        [$deviceId, $expiry, $hmac] = $parts;

        // Check expiry
        if ((int) $expiry < time()) {
            return null;
        }

        // Verify HMAC integrity
        $payload = $deviceId . self::SEPARATOR . $expiry;
        $expectedHmac = Hmac::computeHex($payload, $key);

        if (!hash_equals($expectedHmac, $hmac)) {
            return null;
        }

        return $deviceId;
    }

    public function cookieName(): string
    {
        return $this->cookieName;
    }

    /**
     * Get cookie attributes for Set-Cookie header.
     *
     * @return array{secure: bool, httpOnly: bool, sameSite: string, path: string, maxAge: int}
     */
    public function cookieAttributes(): array
    {
        return [
            'secure' => $this->secure,
            'httpOnly' => $this->httpOnly,
            'sameSite' => $this->sameSite,
            'path' => $this->path,
            'maxAge' => $this->lifetime,
        ];
    }
}
