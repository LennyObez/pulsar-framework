<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\SocialSso\Internal\Token;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\SocialSso\Domain\JwkKey;
use Pulsar\Extension\SocialSso\Internal\Token\OpenSslJwtSignatureDriver;

use function assert;
use function base64_encode;
use function is_string;
use function openssl_pkey_get_details;
use function openssl_pkey_new;
use function openssl_sign;
use function rtrim;
use function str_repeat;
use function strtr;

use const OPENSSL_ALGO_SHA256;
use const OPENSSL_KEYTYPE_EC;
use const OPENSSL_KEYTYPE_RSA;

/**
 * Comprehensive tests for OpenSslJwtSignatureDriver.
 *
 * Tests real cryptographic operations using generated keys for RS256 and ES256,
 * plus adversarial edge cases on signature format validation.
 */
#[CoversClass(OpenSslJwtSignatureDriver::class)]
final class OpenSslJwtSignatureDriverComprehensiveTest extends TestCase
{
    private OpenSslJwtSignatureDriver $driver;

    protected function setUp(): void
    {
        $this->driver = new OpenSslJwtSignatureDriver();
    }

    private static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function algorithmSupportProvider(): array
    {
        return [
            'RS256 supported' => ['RS256', true],
            'ES256 supported' => ['ES256', true],
            'HS256 not supported' => ['HS256', false],
            'none not supported' => ['none', false],
            'RS384 not supported' => ['RS384', false],
            'RS512 not supported' => ['RS512', false],
            'ES384 not supported' => ['ES384', false],
            'ES512 not supported' => ['ES512', false],
            'PS256 not supported' => ['PS256', false],
            'EdDSA not supported' => ['EdDSA', false],
            'empty not supported' => ['', false],
        ];
    }

    #[Test]
    #[DataProvider('algorithmSupportProvider')]
    public function supportsCorrectAlgorithms(string $alg, bool $expected): void
    {
        self::assertSame($expected, $this->driver->supports($alg));
    }

    #[Test]
    public function verifyRs256WithRealKey(): void
    {
        $keyResource = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        if ($keyResource === false) {
            self::markTestSkipped('OpenSSL RSA key generation not available');
        }

        /** @var array{rsa: array{n: string, e: string}} $details */
        $details = openssl_pkey_get_details($keyResource);

        $nB64 = self::base64UrlEncode($details['rsa']['n']);
        $eB64 = self::base64UrlEncode($details['rsa']['e']);

        $jwkKey = new JwkKey(
            kty: 'RSA',
            kid: 'test-rsa',
            alg: 'RS256',
            use: 'sig',
            parameters: ['n' => $nB64, 'e' => $eB64],
        );

        $header = self::base64UrlEncode('{"alg":"RS256","typ":"JWT"}');
        $payload = self::base64UrlEncode('{"sub":"user"}');
        $signingInput = $header . '.' . $payload;

        $signature = '';
        openssl_sign($signingInput, $signature, $keyResource, OPENSSL_ALGO_SHA256);
        assert(is_string($signature));

        $result = $this->driver->verify($header, $payload, $signature, $jwkKey);

        self::assertTrue($result);
    }

    #[Test]
    public function verifyRs256RejectsWrongSignature(): void
    {
        $keyResource = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        if ($keyResource === false) {
            self::markTestSkipped('OpenSSL RSA key generation not available');
        }

        /** @var array{rsa: array{n: string, e: string}} $details */
        $details = openssl_pkey_get_details($keyResource);

        $nB64 = self::base64UrlEncode($details['rsa']['n']);
        $eB64 = self::base64UrlEncode($details['rsa']['e']);

        $jwkKey = new JwkKey(
            kty: 'RSA',
            kid: 'test-rsa',
            alg: 'RS256',
            use: 'sig',
            parameters: ['n' => $nB64, 'e' => $eB64],
        );

        $header = self::base64UrlEncode('{"alg":"RS256"}');
        $payload = self::base64UrlEncode('{"sub":"user"}');

        $result = $this->driver->verify($header, $payload, 'wrong-signature-data', $jwkKey);

        self::assertFalse($result);
    }

    #[Test]
    public function verifyRs256RejectsTamperedPayload(): void
    {
        $keyResource = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        if ($keyResource === false) {
            self::markTestSkipped('OpenSSL RSA key generation not available');
        }

        /** @var array{rsa: array{n: string, e: string}} $details */
        $details = openssl_pkey_get_details($keyResource);

        $nB64 = self::base64UrlEncode($details['rsa']['n']);
        $eB64 = self::base64UrlEncode($details['rsa']['e']);

        $jwkKey = new JwkKey(
            kty: 'RSA',
            kid: 'test-rsa',
            alg: 'RS256',
            use: 'sig',
            parameters: ['n' => $nB64, 'e' => $eB64],
        );

        $header = self::base64UrlEncode('{"alg":"RS256"}');
        $originalPayload = self::base64UrlEncode('{"sub":"user","admin":false}');
        $signingInput = $header . '.' . $originalPayload;

        $signature = '';
        openssl_sign($signingInput, $signature, $keyResource, OPENSSL_ALGO_SHA256);
        assert(is_string($signature));

        // Tamper with payload
        $tamperedPayload = self::base64UrlEncode('{"sub":"user","admin":true}');

        $result = $this->driver->verify($header, $tamperedPayload, $signature, $jwkKey);

        self::assertFalse($result);
    }

    #[Test]
    public function verifyEs256RejectsWrongLengthSignature(): void
    {
        $keyResource = openssl_pkey_new([
            'curve_name' => 'prime256v1',
            'private_key_type' => OPENSSL_KEYTYPE_EC,
        ]);

        if ($keyResource === false) {
            self::markTestSkipped('OpenSSL EC key generation not available');
        }

        /** @var array{ec: array{x: string, y: string}} $details */
        $details = openssl_pkey_get_details($keyResource);

        $xB64 = self::base64UrlEncode($details['ec']['x']);
        $yB64 = self::base64UrlEncode($details['ec']['y']);

        $jwkKey = new JwkKey(
            kty: 'EC',
            kid: 'test-ec',
            alg: 'ES256',
            use: 'sig',
            parameters: ['crv' => 'P-256', 'x' => $xB64, 'y' => $yB64],
        );

        $header = self::base64UrlEncode('{"alg":"ES256"}');
        $payload = self::base64UrlEncode('{"sub":"user"}');

        // Signature is 32 bytes instead of 64 bytes
        $shortSignature = str_repeat("\x01", 32);
        $result = $this->driver->verify($header, $payload, $shortSignature, $jwkKey);

        self::assertFalse($result);
    }

    #[Test]
    public function verifyEs256RejectsTooLongSignature(): void
    {
        $keyResource = openssl_pkey_new([
            'curve_name' => 'prime256v1',
            'private_key_type' => OPENSSL_KEYTYPE_EC,
        ]);

        if ($keyResource === false) {
            self::markTestSkipped('OpenSSL EC key generation not available');
        }

        /** @var array{ec: array{x: string, y: string}} $details */
        $details = openssl_pkey_get_details($keyResource);

        $xB64 = self::base64UrlEncode($details['ec']['x']);
        $yB64 = self::base64UrlEncode($details['ec']['y']);

        $jwkKey = new JwkKey(
            kty: 'EC',
            kid: 'test-ec',
            alg: 'ES256',
            use: 'sig',
            parameters: ['crv' => 'P-256', 'x' => $xB64, 'y' => $yB64],
        );

        $header = self::base64UrlEncode('{"alg":"ES256"}');
        $payload = self::base64UrlEncode('{"sub":"user"}');

        // Signature is 65 bytes instead of 64
        $longSignature = str_repeat("\x01", 65);
        $result = $this->driver->verify($header, $payload, $longSignature, $jwkKey);

        self::assertFalse($result);
    }

    #[Test]
    public function verifyReturnsFalseForUnsupportedKeyType(): void
    {
        $key = new JwkKey(kty: 'oct', kid: 'hmac', alg: 'HS256');

        $result = $this->driver->verify('h', 'p', 'sig', $key);

        self::assertFalse($result);
    }

    #[Test]
    public function verifyReturnsFalseForKeyWithDefaultAlgorithm(): void
    {
        // Key with a non-RS256/non-ES256 algorithm falls into the default match case
        $key = new JwkKey(kty: 'RSA', kid: 'rsa', alg: 'PS256', parameters: [
            'n' => 'dummy',
            'e' => 'AQAB',
        ]);

        $result = $this->driver->verify('h', 'p', 'sig', $key);

        self::assertFalse($result);
    }

    #[Test]
    public function verifyReturnsFalseForKeyWithEmptyAlg(): void
    {
        $key = new JwkKey(kty: 'RSA', kid: 'rsa', alg: '', parameters: [
            'n' => 'dummy',
            'e' => 'AQAB',
        ]);

        $result = $this->driver->verify('h', 'p', 'sig', $key);

        self::assertFalse($result);
    }

    #[Test]
    public function verifyEs256WithRandomButCorrectLengthSignatureReturnsFalse(): void
    {
        $keyResource = openssl_pkey_new([
            'curve_name' => 'prime256v1',
            'private_key_type' => OPENSSL_KEYTYPE_EC,
        ]);

        if ($keyResource === false) {
            self::markTestSkipped('OpenSSL EC key generation not available');
        }

        /** @var array{ec: array{x: string, y: string}} $details */
        $details = openssl_pkey_get_details($keyResource);

        $xB64 = self::base64UrlEncode($details['ec']['x']);
        $yB64 = self::base64UrlEncode($details['ec']['y']);

        $jwkKey = new JwkKey(
            kty: 'EC',
            kid: 'test-ec',
            alg: 'ES256',
            use: 'sig',
            parameters: ['crv' => 'P-256', 'x' => $xB64, 'y' => $yB64],
        );

        $header = self::base64UrlEncode('{"alg":"ES256"}');
        $payload = self::base64UrlEncode('{"sub":"user"}');

        // 64 bytes but random -- wrong signature
        $randomSig = str_repeat("\xAB", 32) . str_repeat("\xCD", 32);
        $result = $this->driver->verify($header, $payload, $randomSig, $jwkKey);

        self::assertFalse($result);
    }

    #[Test]
    public function verifyEs256WithHighBitRComponents(): void
    {
        // Test the ecRawToDer branch where r[0] > 0x7f (needs 0x00 prefix)
        $keyResource = openssl_pkey_new([
            'curve_name' => 'prime256v1',
            'private_key_type' => OPENSSL_KEYTYPE_EC,
        ]);

        if ($keyResource === false) {
            self::markTestSkipped('OpenSSL EC key generation not available');
        }

        /** @var array{ec: array{x: string, y: string}} $details */
        $details = openssl_pkey_get_details($keyResource);

        $xB64 = self::base64UrlEncode($details['ec']['x']);
        $yB64 = self::base64UrlEncode($details['ec']['y']);

        $jwkKey = new JwkKey(
            kty: 'EC',
            kid: 'test-ec',
            alg: 'ES256',
            use: 'sig',
            parameters: ['crv' => 'P-256', 'x' => $xB64, 'y' => $yB64],
        );

        // Craft a signature where R starts with 0xFF (high bit set)
        // S starts with 0x80 (also high bit set)
        // Both need 0x00 prefix in DER
        $r = "\xFF" . str_repeat("\x01", 31);
        $s = "\x80" . str_repeat("\x02", 31);
        $sig = $r . $s;

        $header = self::base64UrlEncode('{"alg":"ES256"}');
        $payload = self::base64UrlEncode('{"sub":"user"}');

        // This should not crash (DER encoding handles high bits) - will return false due to wrong sig
        $result = $this->driver->verify($header, $payload, $sig, $jwkKey);

        self::assertFalse($result);
    }

    #[Test]
    public function verifyEs256WithLeadingZeroRComponents(): void
    {
        // Test the trimLeadingZeros branch - r with leading zeros
        $keyResource = openssl_pkey_new([
            'curve_name' => 'prime256v1',
            'private_key_type' => OPENSSL_KEYTYPE_EC,
        ]);

        if ($keyResource === false) {
            self::markTestSkipped('OpenSSL EC key generation not available');
        }

        /** @var array{ec: array{x: string, y: string}} $details */
        $details = openssl_pkey_get_details($keyResource);

        $xB64 = self::base64UrlEncode($details['ec']['x']);
        $yB64 = self::base64UrlEncode($details['ec']['y']);

        $jwkKey = new JwkKey(
            kty: 'EC',
            kid: 'test-ec',
            alg: 'ES256',
            use: 'sig',
            parameters: ['crv' => 'P-256', 'x' => $xB64, 'y' => $yB64],
        );

        // R with leading zeros that get trimmed
        $r = "\x00\x00\x00\x01" . str_repeat("\x42", 28);
        $s = str_repeat("\x01", 32);
        $sig = $r . $s;

        $header = self::base64UrlEncode('{"alg":"ES256"}');
        $payload = self::base64UrlEncode('{"sub":"user"}');

        // Should not crash - returns false because signature is invalid
        $result = $this->driver->verify($header, $payload, $sig, $jwkKey);

        self::assertFalse($result);
    }

    #[Test]
    public function verifyReturnsFalseWhenPemCannotBeGenerated(): void
    {
        // EC key missing parameters -> getPublicKeyPem() returns null
        $jwkKey = new JwkKey(
            kty: 'EC',
            kid: 'broken-ec',
            alg: 'ES256',
            use: 'sig',
            parameters: [], // Missing crv, x, y
        );

        $result = $this->driver->verify('header', 'payload', str_repeat("\x01", 64), $jwkKey);

        self::assertFalse($result);
    }

    #[Test]
    public function verifyRs256WithDifferentKeyRejectsCrossKeySignature(): void
    {
        $key1 = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $key2 = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);

        if ($key1 === false || $key2 === false) {
            self::markTestSkipped('OpenSSL RSA key generation not available');
        }

        // Sign with key1
        $header = self::base64UrlEncode('{"alg":"RS256"}');
        $payload = self::base64UrlEncode('{"sub":"user"}');
        $signingInput = $header . '.' . $payload;

        $signature = '';
        openssl_sign($signingInput, $signature, $key1, OPENSSL_ALGO_SHA256);
        assert(is_string($signature));

        // Verify with key2 (different key)
        /** @var array{rsa: array{n: string, e: string}} $details2 */
        $details2 = openssl_pkey_get_details($key2);

        $jwkKey2 = new JwkKey(
            kty: 'RSA',
            kid: 'key2',
            alg: 'RS256',
            parameters: [
                'n' => self::base64UrlEncode($details2['rsa']['n']),
                'e' => self::base64UrlEncode($details2['rsa']['e']),
            ],
        );

        $result = $this->driver->verify($header, $payload, $signature, $jwkKey2);

        self::assertFalse($result);
    }
}
