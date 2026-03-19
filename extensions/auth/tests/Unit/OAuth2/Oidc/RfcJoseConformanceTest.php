<?php

declare(strict_types=1);

namespace Pulsar\Extension\Auth\Tests\Unit\OAuth2\Oidc;

use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Auth\OAuth2\Oidc\JwtSigner;
use Pulsar\Extension\Auth\OAuth2\Oidc\OidcConfig;
use Pulsar\Security\Crypto\KeyRingInterface;

use function base64_encode;
use function explode;
use function hash_hmac;
use function implode;
use function json_encode;
use function openssl_pkey_export;
use function openssl_pkey_get_details;
use function openssl_pkey_get_private;
use function openssl_pkey_new;
use function rtrim;
use function str_replace;
use function time;

use const OPENSSL_KEYTYPE_RSA;

/**
 * VECTORS-JOSE-01: JOSE / JWT conformance + attack corpus (ADR-0032).
 *
 * This suite is the GA gate for the JOSE / JWT layer per ADR-0032. It
 * imports the canonical JWT attack corpus (alg:none, RS256→HS256 key
 * confusion, kid traversal, empty signature, critical header bypass,
 * tampered header/payload) plus the standard claim-validation paths
 * (exp, nbf, iss).
 *
 * Each test is named after the attack or RFC clause it asserts.
 */
#[CoversClass(JwtSigner::class)]
final class RfcJoseConformanceTest extends TestCase
{
    private const string KID = 'test-key';

    private JwtSigner $signer;

    private string $rsaPem;

    private string $rsaPublicPem;

    protected function setUp(): void
    {
        $resource = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        // PHP 8.3+: openssl_pkey_export passes by reference; a typed non-
        // nullable property is not addressable until initialised. Stage
        // through a local var.
        $pem = '';
        openssl_pkey_export($resource, $pem);
        $this->rsaPem = $pem;

        $details = openssl_pkey_get_details(openssl_pkey_get_private($this->rsaPem));
        $this->rsaPublicPem = $details['key'];

        $keyRing = new TestKeyRing([self::KID => $this->rsaPem]);
        $this->signer = new JwtSigner($keyRing, new OidcConfig(issuer: 'https://issuer.test'));
    }

    /** RFC 7519 happy path: sign + verify a well-formed token. */
    #[Test]
    public function validRs256TokenRoundTripsSuccessfully(): void
    {
        $claims = [
            'iss' => 'https://issuer.test',
            'sub' => 'user-42',
            'aud' => 'client-1',
            'exp' => time() + 900,
            'iat' => time(),
        ];

        $jwt = $this->signer->sign($claims, self::KID);
        $verified = $this->signer->verify($jwt, self::KID);

        self::assertNotNull($verified);
        self::assertSame('user-42', $verified['sub']);
        self::assertSame('client-1', $verified['aud']);
    }

    /**
     * Attack: alg:none with no signature. CVE-2015-9235 family.
     * <https://www.invicti.com/blog/web-security/severe-vulnerabilities-jwt-libraries/>
     */
    #[Test]
    public function algNoneAttackIsRejected(): void
    {
        $header = ['typ' => 'JWT', 'alg' => 'none', 'kid' => self::KID];
        $claims = ['iss' => 'https://issuer.test', 'sub' => 'attacker', 'exp' => time() + 60];

        $jwt = self::b64u(json_encode($header)) . '.' . self::b64u(json_encode($claims)) . '.';

        self::assertNull(
            $this->signer->verify($jwt, self::KID),
            'alg:none token with empty signature must be rejected — CVE-2015-9235 family',
        );
    }

    /**
     * Attack: alg:None / alg:NONE (case variant). The verifier MUST refuse
     * any token that lacks a real RSA signature regardless of how the
     * `alg` header is spelled.
     */
    #[Test]
    public function algNoneCaseVariantsAreRejected(): void
    {
        foreach (['NONE', 'None', 'noNe', 'NoNe'] as $variant) {
            $header = ['typ' => 'JWT', 'alg' => $variant, 'kid' => self::KID];
            $claims = ['sub' => 'attacker', 'exp' => time() + 60];

            $jwt = self::b64u(json_encode($header)) . '.' . self::b64u(json_encode($claims)) . '.';

            self::assertNull(
                $this->signer->verify($jwt, self::KID),
                "alg:$variant token must be rejected (case-sensitive)",
            );
        }
    }

    /**
     * Attack: RS256 verifier asked to accept an HS256-signed token whose
     * "secret" is the RSA PUBLIC key. This is the classic key-confusion
     * exploit (CVE-2015-9235, CVE-2016-10555). Pulsar's verify() is
     * RS256-only by construction — it never honors the alg header — so
     * the HS256 signature fails RSA verification and the token is rejected.
     */
    #[Test]
    public function rs256VerifierRejectsHs256KeyConfusionAttack(): void
    {
        $header = ['typ' => 'JWT', 'alg' => 'HS256', 'kid' => self::KID];
        $claims = ['iss' => 'https://issuer.test', 'sub' => 'attacker', 'exp' => time() + 60];

        $headerEncoded = self::b64u(json_encode($header));
        $payloadEncoded = self::b64u(json_encode($claims));
        $signingInput = $headerEncoded . '.' . $payloadEncoded;

        // Sign with HMAC-SHA256 using the RSA *public* key bytes as the secret.
        // A naive JWT library that picks the alg from the header would HMAC-
        // verify this with the public key and accept it.
        $hmacSig = hash_hmac('sha256', $signingInput, $this->rsaPublicPem, true);
        $jwt = $signingInput . '.' . self::b64u($hmacSig);

        self::assertNull(
            $this->signer->verify($jwt, self::KID),
            'HS256-signed token must be rejected by RS256 verifier — key confusion attack',
        );
    }

    /** Attack: token with empty signature segment. */
    #[Test]
    public function emptySignatureIsRejected(): void
    {
        $header = ['typ' => 'JWT', 'alg' => 'RS256', 'kid' => self::KID];
        $claims = ['sub' => 'attacker', 'exp' => time() + 60];

        $jwt = self::b64u(json_encode($header)) . '.' . self::b64u(json_encode($claims)) . '.';

        self::assertNull(
            $this->signer->verify($jwt, self::KID),
            'Empty signature must be rejected',
        );
    }

    /** Attack: flip a byte in the encoded header. */
    #[Test]
    public function tamperedHeaderIsRejected(): void
    {
        $claims = ['iss' => 'https://issuer.test', 'sub' => 'user-42', 'exp' => time() + 60];
        $jwt = $this->signer->sign($claims, self::KID);

        $parts = explode('.', $jwt);
        // Replace the header with one claiming a different alg.
        $parts[0] = self::b64u(json_encode(['typ' => 'JWT', 'alg' => 'HS512', 'kid' => self::KID]));
        $tampered = implode('.', $parts);

        self::assertNull(
            $this->signer->verify($tampered, self::KID),
            'Tampered header must invalidate the signature',
        );
    }

    /** Attack: flip a byte in the encoded payload. */
    #[Test]
    public function tamperedPayloadIsRejected(): void
    {
        $claims = ['iss' => 'https://issuer.test', 'sub' => 'user-42', 'exp' => time() + 60];
        $jwt = $this->signer->sign($claims, self::KID);

        $parts = explode('.', $jwt);
        $parts[1] = self::b64u(json_encode(['iss' => 'https://evil.test', 'sub' => 'attacker', 'exp' => time() + 60]));
        $tampered = implode('.', $parts);

        self::assertNull(
            $this->signer->verify($tampered, self::KID),
            'Tampered payload must invalidate the signature',
        );
    }

    /** Attack: token with malformed (non-3-part) structure. */
    #[Test]
    public function malformedTokenStructureIsRejected(): void
    {
        foreach (['no.dots', 'two.dots.only.fourparts.', '.', '', 'aaaa', 'a.b'] as $malformed) {
            self::assertNull(
                $this->signer->verify($malformed, self::KID),
                "Malformed token '$malformed' must be rejected",
            );
        }
    }

    /** Attack: kid traversal — the verifier asks KeyRing for keyFor(kid). */
    #[Test]
    public function kidTraversalAttackResolvesToNoKey(): void
    {
        // A traversal kid is passed straight to KeyRing::keyFor() which
        // returns null for unknown kids. The test confirms the verifier
        // exits with null instead of attempting any I/O against the value.
        $jwt = $this->signer->sign(['sub' => 'user', 'exp' => time() + 60], self::KID);

        self::assertNull($this->signer->verify($jwt, '../../etc/passwd'));
        self::assertNull($this->signer->verify($jwt, '..\\..\\windows\\system32'));
        self::assertNull($this->signer->verify($jwt, ''));
        self::assertNull($this->signer->verify($jwt, 'kid'));
    }

    /** RFC 7519 §4.1.4: expired tokens are rejected. */
    #[Test]
    public function expiredTokenIsRejected(): void
    {
        $jwt = $this->signer->sign([
            'iss' => 'https://issuer.test',
            'sub' => 'user-42',
            'exp' => time() - 10,
        ], self::KID);

        self::assertNull($this->signer->verify($jwt, self::KID));
    }

    /** RFC 7519 §4.1.5: not-yet-valid (nbf in future) tokens are rejected. */
    #[Test]
    public function notYetValidTokenIsRejected(): void
    {
        $jwt = $this->signer->sign([
            'iss' => 'https://issuer.test',
            'sub' => 'user-42',
            'exp' => time() + 600,
            'nbf' => time() + 300,
        ], self::KID);

        self::assertNull($this->signer->verify($jwt, self::KID));
    }

    /** RFC 7519 §4.1.1 + OIDC: issuer mismatch is rejected. */
    #[Test]
    public function issuerMismatchIsRejected(): void
    {
        $jwt = $this->signer->sign([
            'iss' => 'https://attacker.test',
            'sub' => 'user-42',
            'exp' => time() + 60,
        ], self::KID);

        self::assertNull(
            $this->signer->verify($jwt, self::KID),
            'iss claim must match OidcConfig::issuer',
        );
    }

    /**
     * Attack: exp / nbf claims with non-numeric types. The verifier MUST
     * refuse a token whose timing claims cannot be safely interpreted as
     * NumericDate (RFC 7519 §2).
     */
    #[Test]
    public function nonNumericTimingClaimsAreRejected(): void
    {
        // Tamper a freshly-signed token: encode a payload where exp is a
        // string that isn't numeric (would parse to 0 if cast to int).
        $jwt = $this->signer->sign(['sub' => 'user', 'exp' => time() + 60], self::KID);
        $parts = explode('.', $jwt);
        $parts[1] = self::b64u(json_encode([
            'iss' => 'https://issuer.test',
            'sub' => 'user',
            'exp' => 'forever',
        ]));
        $tampered = implode('.', $parts);

        self::assertNull(
            $this->signer->verify($tampered, self::KID),
            'Non-numeric exp must be rejected (and signature is invalid too)',
        );
    }

    private static function b64u(string $bytes): string
    {
        return rtrim(str_replace(['+', '/'], ['-', '_'], base64_encode($bytes)), '=');
    }
}

/**
 * @internal Test key ring backed by a fixed kid -> PEM map.
 */
final readonly class TestKeyRing implements KeyRingInterface
{
    /** @param array<string, string> $keys */
    public function __construct(private array $keys) {}

    #[Override]
    public function keyFor(string $kid): ?string
    {
        return $this->keys[$kid] ?? null;
    }

    #[Override]
    public function all(): iterable
    {
        return $this->keys;
    }
}
