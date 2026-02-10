<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\SocialSso\Domain;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\SocialSso\Domain\JwkKey;

#[CoversClass(JwkKey::class)]
final class JwkKeyTest extends TestCase
{
    #[Test]
    public function constructionWithMinimalFields(): void
    {
        $key = new JwkKey(kty: 'RSA');

        self::assertSame('RSA', $key->kty);
        self::assertNull($key->kid);
        self::assertNull($key->alg);
        self::assertNull($key->use);
        self::assertSame([], $key->parameters);
    }

    #[Test]
    public function constructionWithAllFields(): void
    {
        $key = new JwkKey(
            kty: 'EC',
            kid: 'key-1',
            alg: 'ES256',
            use: 'sig',
            parameters: ['crv' => 'P-256', 'x' => 'abc', 'y' => 'def'],
        );

        self::assertSame('EC', $key->kty);
        self::assertSame('key-1', $key->kid);
        self::assertSame('ES256', $key->alg);
        self::assertSame('sig', $key->use);
        self::assertSame('P-256', $key->parameters['crv']);
    }

    #[Test]
    public function getPublicKeyPemReturnsNullForUnsupportedKeyType(): void
    {
        $key = new JwkKey(kty: 'oct');

        self::assertNull($key->getPublicKeyPem());
    }

    #[Test]
    public function getPublicKeyPemReturnsNullForRsaWithoutModulus(): void
    {
        $key = new JwkKey(
            kty: 'RSA',
            parameters: ['e' => 'AQAB'],
        );

        self::assertNull($key->getPublicKeyPem());
    }

    #[Test]
    public function getPublicKeyPemReturnsNullForRsaWithoutExponent(): void
    {
        $key = new JwkKey(
            kty: 'RSA',
            parameters: ['n' => 'someModulus'],
        );

        self::assertNull($key->getPublicKeyPem());
    }

    #[Test]
    public function getPublicKeyPemReturnsNullForEcWithoutCurve(): void
    {
        $key = new JwkKey(
            kty: 'EC',
            parameters: ['x' => 'abc', 'y' => 'def'],
        );

        self::assertNull($key->getPublicKeyPem());
    }

    #[Test]
    public function getPublicKeyPemReturnsNullForEcWithoutX(): void
    {
        $key = new JwkKey(
            kty: 'EC',
            parameters: ['crv' => 'P-256', 'y' => 'def'],
        );

        self::assertNull($key->getPublicKeyPem());
    }

    #[Test]
    public function getPublicKeyPemReturnsNullForEcWithoutY(): void
    {
        $key = new JwkKey(
            kty: 'EC',
            parameters: ['crv' => 'P-256', 'x' => 'abc'],
        );

        self::assertNull($key->getPublicKeyPem());
    }

    #[Test]
    public function getPublicKeyPemReturnsNullForUnsupportedEcCurve(): void
    {
        $key = new JwkKey(
            kty: 'EC',
            parameters: [
                'crv' => 'P-192',
                'x' => base64_encode(str_repeat("\x01", 24)),
                'y' => base64_encode(str_repeat("\x02", 24)),
            ],
        );

        self::assertNull($key->getPublicKeyPem());
    }

    #[Test]
    public function getPublicKeyPemReturnsNullForEmptyBase64UrlParams(): void
    {
        $key = new JwkKey(
            kty: 'RSA',
            parameters: ['n' => '', 'e' => ''],
        );

        self::assertNull($key->getPublicKeyPem());
    }

    #[Test]
    public function getPublicKeyPemGeneratesValidRsaPem(): void
    {
        // Well-known RSA test key JWK parameters (from RFC 7517 example, simplified)
        // Use a known RSA key for deterministic testing
        $rsaKey = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        if ($rsaKey === false) {
            self::markTestSkipped('OpenSSL RSA key generation not available');
        }

        /** @var array{rsa: array{n: string, e: string}} $details */
        $details = openssl_pkey_get_details($rsaKey);

        $nBase64Url = rtrim(strtr(base64_encode($details['rsa']['n']), '+/', '-_'), '=');
        $eBase64Url = rtrim(strtr(base64_encode($details['rsa']['e']), '+/', '-_'), '=');

        $key = new JwkKey(
            kty: 'RSA',
            kid: 'test-rsa',
            alg: 'RS256',
            use: 'sig',
            parameters: ['n' => $nBase64Url, 'e' => $eBase64Url],
        );

        $pem = $key->getPublicKeyPem();

        self::assertNotNull($pem);
        self::assertStringStartsWith('-----BEGIN PUBLIC KEY-----', $pem);
        self::assertStringContainsString('-----END PUBLIC KEY-----', $pem);

        // Verify it's a valid PEM that OpenSSL can parse
        $publicKey = openssl_pkey_get_public($pem);
        self::assertNotFalse($publicKey);
    }
}
