<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\OAuth2\Oidc;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\OAuth2\Oidc\JwtSigner;
use Pulsar\Security\Crypto\KeyRingInterface;
use RuntimeException;

#[CoversClass(JwtSigner::class)]
final class JwtSignerTest extends TestCase
{
    private JwtSigner $signer;
    private string $signingKey;

    protected function setUp(): void
    {
        $this->signingKey = random_bytes(32);

        $keyRing = $this->createMock(KeyRingInterface::class);
        $keyRing->method('keyFor')
            ->willReturnCallback(fn(string $kid): ?string => match ($kid) {
                'test_key' => $this->signingKey,
                default => null,
            });

        $this->signer = new JwtSigner($keyRing);
    }

    #[Test]
    public function signProducesThreePartJwt(): void
    {
        $claims = ['iss' => 'https://auth.example.com', 'sub' => 'user-42'];

        $jwt = $this->signer->sign($claims, 'test_key');

        $parts = explode('.', $jwt);
        self::assertCount(3, $parts);
    }

    #[Test]
    public function signedJwtCanBeVerified(): void
    {
        $claims = [
            'iss' => 'https://auth.example.com',
            'sub' => 'user-42',
            'aud' => 'client-1',
            'exp' => time() + 900,
            'iat' => time(),
        ];

        $jwt = $this->signer->sign($claims, 'test_key');
        $verified = $this->signer->verify($jwt, 'test_key');

        self::assertNotNull($verified);
        self::assertSame('https://auth.example.com', $verified['iss']);
        self::assertSame('user-42', $verified['sub']);
        self::assertSame('client-1', $verified['aud']);
    }

    #[Test]
    public function verifyReturnsNullForTamperedPayload(): void
    {
        $claims = ['iss' => 'https://auth.example.com', 'sub' => 'user-42'];
        $jwt = $this->signer->sign($claims, 'test_key');

        // Tamper with the payload
        $parts = explode('.', $jwt);
        $parts[1] = rtrim(strtr(base64_encode('{"iss":"https://evil.com","sub":"attacker"}'), '+/', '-_'), '=');
        $tampered = implode('.', $parts);

        $result = $this->signer->verify($tampered, 'test_key');

        self::assertNull($result);
    }

    #[Test]
    public function verifyReturnsNullForInvalidFormat(): void
    {
        $result = $this->signer->verify('not-a-jwt', 'test_key');

        self::assertNull($result);
    }

    #[Test]
    public function verifyReturnsNullForUnknownKey(): void
    {
        $claims = ['iss' => 'https://auth.example.com'];
        $jwt = $this->signer->sign($claims, 'test_key');

        $result = $this->signer->verify($jwt, 'unknown_key');

        self::assertNull($result);
    }

    #[Test]
    public function signThrowsForUnknownKey(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Signing key 'nonexistent' not found");

        $this->signer->sign(['sub' => 'user'], 'nonexistent');
    }

    #[Test]
    public function headerContainsCorrectFields(): void
    {
        $jwt = $this->signer->sign(['sub' => 'user-42'], 'test_key');

        $parts = explode('.', $jwt);
        $decoded = base64_decode(strtr($parts[0], '-_', '+/'), true);
        self::assertIsString($decoded);

        /** @var array<string, mixed> $header */
        $header = json_decode($decoded, true);

        self::assertSame('JWT', $header['typ']);
        self::assertSame('HS256', $header['alg']);
        self::assertSame('test_key', $header['kid']);
    }

    #[Test]
    public function idTokenRequiredClaimsPreserved(): void
    {
        $now = time();
        $claims = [
            'iss' => 'https://auth.example.com',
            'sub' => 'user-42',
            'aud' => 'client-1',
            'exp' => $now + 900,
            'iat' => $now,
            'nonce' => 'random-nonce-value',
        ];

        $jwt = $this->signer->sign($claims, 'test_key');
        $decoded = $this->signer->verify($jwt, 'test_key');

        self::assertNotNull($decoded);
        self::assertSame('https://auth.example.com', $decoded['iss']);
        self::assertSame('user-42', $decoded['sub']);
        self::assertSame('client-1', $decoded['aud']);
        self::assertSame($now + 900, $decoded['exp']);
        self::assertSame($now, $decoded['iat']);
        self::assertSame('random-nonce-value', $decoded['nonce']);
    }
}
