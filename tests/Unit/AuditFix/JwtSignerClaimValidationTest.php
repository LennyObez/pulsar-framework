<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\AuditFix;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\OAuth2\Oidc\JwtSigner;
use Pulsar\Extension\OAuth2\Oidc\OidcConfig;

use function time;

/**
 * Verifies that JwtSigner::verify() validates JWT claims (exp, nbf, iss, aud)
 * after signature verification, rejecting expired, not-yet-valid, or mismatched tokens.
 */
#[CoversClass(JwtSigner::class)]
final class JwtSignerClaimValidationTest extends TestCase
{
    private string $privateKeyPem = '';
    private OidcConfig $config;
    private JwtSigner $signer;

    protected function setUp(): void
    {
        // Generate a test RSA key pair
        $keyRes = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        self::assertNotFalse($keyRes, 'Failed to generate RSA key for test');

        $pem = '';
        openssl_pkey_export($keyRes, $pem);
        $this->privateKeyPem = $pem;

        $this->config = new OidcConfig(
            issuer: 'https://auth.example.com',
            signingKey: $this->privateKeyPem,
        );

        $this->signer = new JwtSigner($this->config);
    }

    #[Test]
    public function validTokenWithAllClaimsIsAccepted(): void
    {
        $claims = [
            'sub' => 'user-123',
            'iss' => 'https://auth.example.com',
            'aud' => 'https://auth.example.com',
            'exp' => time() + 3600,
            'nbf' => time() - 60,
            'iat' => time(),
        ];

        $jwt = $this->signer->sign($claims, 'kid-1');
        $decoded = $this->signer->verify($jwt);

        self::assertNotNull($decoded);
        self::assertSame('user-123', $decoded['sub']);
    }

    #[Test]
    public function expiredTokenIsRejected(): void
    {
        $claims = [
            'sub' => 'user-123',
            'iss' => 'https://auth.example.com',
            'aud' => 'https://auth.example.com',
            'exp' => time() - 100,
            'iat' => time() - 3700,
        ];

        $jwt = $this->signer->sign($claims, 'kid-1');
        $decoded = $this->signer->verify($jwt);

        self::assertNull($decoded, 'Expired token should be rejected');
    }

    #[Test]
    public function notYetValidTokenIsRejected(): void
    {
        $claims = [
            'sub' => 'user-123',
            'iss' => 'https://auth.example.com',
            'aud' => 'https://auth.example.com',
            'exp' => time() + 7200,
            'nbf' => time() + 3600,
            'iat' => time(),
        ];

        $jwt = $this->signer->sign($claims, 'kid-1');
        $decoded = $this->signer->verify($jwt);

        self::assertNull($decoded, 'Token with future nbf should be rejected');
    }

    #[Test]
    public function wrongIssuerIsRejected(): void
    {
        $claims = [
            'sub' => 'user-123',
            'iss' => 'https://evil.example.com',
            'aud' => 'https://auth.example.com',
            'exp' => time() + 3600,
        ];

        $jwt = $this->signer->sign($claims, 'kid-1');
        $decoded = $this->signer->verify($jwt);

        self::assertNull($decoded, 'Token with wrong issuer should be rejected');
    }

    #[Test]
    public function wrongAudienceIsRejected(): void
    {
        $claims = [
            'sub' => 'user-123',
            'iss' => 'https://auth.example.com',
            'aud' => 'https://wrong-audience.example.com',
            'exp' => time() + 3600,
        ];

        $jwt = $this->signer->sign($claims, 'kid-1');
        $decoded = $this->signer->verify($jwt);

        self::assertNull($decoded, 'Token with wrong audience should be rejected');
    }

    #[Test]
    public function audienceAsArrayIsAcceptedWhenIssuerIsPresent(): void
    {
        $claims = [
            'sub' => 'user-123',
            'iss' => 'https://auth.example.com',
            'aud' => ['https://auth.example.com', 'https://api.example.com'],
            'exp' => time() + 3600,
        ];

        $jwt = $this->signer->sign($claims, 'kid-1');
        $decoded = $this->signer->verify($jwt);

        self::assertNotNull($decoded);
    }

    #[Test]
    public function audienceArrayWithoutIssuerIsRejected(): void
    {
        $claims = [
            'sub' => 'user-123',
            'iss' => 'https://auth.example.com',
            'aud' => ['https://other.example.com', 'https://api.example.com'],
            'exp' => time() + 3600,
        ];

        $jwt = $this->signer->sign($claims, 'kid-1');
        $decoded = $this->signer->verify($jwt);

        self::assertNull($decoded, 'Token with aud array not containing issuer should be rejected');
    }

    #[Test]
    public function invalidSignatureIsRejected(): void
    {
        $claims = [
            'sub' => 'user-123',
            'iss' => 'https://auth.example.com',
            'exp' => time() + 3600,
        ];

        $jwt = $this->signer->sign($claims, 'kid-1');
        // Corrupt the signature portion
        $jwt .= 'tampered';
        $decoded = $this->signer->verify($jwt);

        self::assertNull($decoded);
    }

    #[Test]
    public function malformedJwtIsRejected(): void
    {
        $decoded = $this->signer->verify('not-a-jwt');

        self::assertNull($decoded);
    }
}
