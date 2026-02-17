<?php

declare(strict_types=1);

namespace Pulsar\Extension\OAuth2\Tests\Unit\Oidc;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\OAuth2\Oidc\JwtSigner;
use Pulsar\Extension\OAuth2\Oidc\OidcConfig;
use RuntimeException;

#[CoversClass(JwtSigner::class)]
final class JwtSignerTest extends TestCase
{
    private string $privateKeyPem = '';
    private OidcConfig $config;
    private JwtSigner $signer;

    protected function setUp(): void
    {
        $keyPair = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        self::assertNotFalse($keyPair);

        $pem = '';
        openssl_pkey_export($keyPair, $pem);
        self::assertIsString($pem);
        $this->privateKeyPem = $pem;

        $this->config = new OidcConfig(
            issuer: 'https://auth.example.com',
            signingKey: $this->privateKeyPem,
        );

        $this->signer = new JwtSigner($this->config);
    }

    #[Test]
    public function signProducesThreePartJwt(): void
    {
        $jwt = $this->signer->sign(['sub' => 'user-1'], 'key-1');

        $parts = explode('.', $jwt);
        self::assertCount(3, $parts);
    }

    #[Test]
    public function signUsesRs256Algorithm(): void
    {
        $jwt = $this->signer->sign(['sub' => 'user-1'], 'key-1');

        $headerJson = base64_decode(strtr(explode('.', $jwt)[0], '-_', '+/'), true);
        self::assertIsString($headerJson);
        /** @var array<string, mixed> $header */
        $header = json_decode($headerJson, true);
        self::assertIsArray($header);

        self::assertSame('RS256', $header['alg']);
        self::assertSame('JWT', $header['typ']);
        self::assertSame('key-1', $header['kid']);
    }

    #[Test]
    public function signAndVerifyRoundTrip(): void
    {
        $claims = [
            'sub' => 'user-42',
            'iss' => 'https://auth.example.com',
            'aud' => 'client-1',
            'exp' => time() + 3600,
        ];

        $jwt = $this->signer->sign($claims, 'oidc-key');
        $decoded = $this->signer->verify($jwt);

        self::assertNotNull($decoded);
        /** @var array<string, mixed> $decoded */
        self::assertSame('user-42', $decoded['sub']);
        self::assertSame('https://auth.example.com', $decoded['iss']);
        self::assertSame('client-1', $decoded['aud']);
    }

    #[Test]
    public function verifyRejectsInvalidJwtFormat(): void
    {
        self::assertNull($this->signer->verify('not-a-jwt'));
        self::assertNull($this->signer->verify('a.b'));
        self::assertNull($this->signer->verify('a.b.c.d'));
    }

    #[Test]
    public function verifyRejectsTamperedPayload(): void
    {
        $jwt = $this->signer->sign(['sub' => 'user-1'], 'key-1');
        $parts = explode('.', $jwt);

        // Tamper with the payload
        $tamperedPayload = rtrim(strtr(base64_encode('{"sub":"admin"}'), '+/', '-_'), '=');
        $tamperedJwt = $parts[0] . '.' . $tamperedPayload . '.' . $parts[2];

        self::assertNull($this->signer->verify($tamperedJwt));
    }

    #[Test]
    public function verifyRejectsTamperedSignature(): void
    {
        $jwt = $this->signer->sign(['sub' => 'user-1'], 'key-1');
        $parts = explode('.', $jwt);

        // Corrupt the signature
        $corruptedJwt = $parts[0] . '.' . $parts[1] . '.AAAA';

        self::assertNull($this->signer->verify($corruptedJwt));
    }

    #[Test]
    public function signThrowsWithInvalidPrivateKey(): void
    {
        $config = new OidcConfig(
            issuer: 'https://auth.example.com',
            signingKey: 'not-a-key',
        );

        $signer = new JwtSigner($config);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid RSA private key');

        $signer->sign(['sub' => 'user-1'], 'key-1');
    }

    #[Test]
    public function verifyReturnsNullWithInvalidPrivateKey(): void
    {
        $jwt = $this->signer->sign(['sub' => 'user-1'], 'key-1');

        $badConfig = new OidcConfig(
            issuer: 'https://auth.example.com',
            signingKey: 'not-a-key',
        );

        $badSigner = new JwtSigner($badConfig);

        self::assertNull($badSigner->verify($jwt));
    }

    #[Test]
    public function verifyRejectsJwtSignedWithDifferentKey(): void
    {
        $jwt = $this->signer->sign(['sub' => 'user-1'], 'key-1');

        // Generate a different key pair
        $otherKey = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        self::assertNotFalse($otherKey);
        $otherPem = '';
        openssl_pkey_export($otherKey, $otherPem);
        self::assertIsString($otherPem);

        $otherConfig = new OidcConfig(
            issuer: 'https://auth.example.com',
            signingKey: $otherPem,
        );

        $otherSigner = new JwtSigner($otherConfig);

        self::assertNull($otherSigner->verify($jwt));
    }
}
