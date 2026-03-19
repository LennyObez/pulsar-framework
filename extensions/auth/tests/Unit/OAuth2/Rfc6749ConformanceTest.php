<?php

declare(strict_types=1);

namespace Pulsar\Extension\Auth\Tests\Unit\OAuth2;

use DateTimeImmutable;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Auth\Identity\TwoFactorStatus;
use Pulsar\Extension\Auth\OAuth2\Adapter\OAuth2TokenResolver;
use Pulsar\Extension\Auth\OAuth2\Contract\AccessTokenRepositoryInterface;
use Pulsar\Extension\Auth\OAuth2\Contract\UserClaimsProviderInterface;
use Pulsar\Extension\Auth\OAuth2\Token\AccessToken;
use Pulsar\Extension\Auth\Social\Domain\PkceChallenge;

use function base64_encode;
use function hash;
use function rtrim;
use function str_replace;

/**
 * VECTORS-OAUTH-01: OAuth2 / OIDC conformance vectors (ADR-0032).
 *
 * GA-gate suite per ADR-0032. Covers:
 *   - RFC 7636 §A.1 PKCE S256 canonical example (challenge derivation).
 *   - RFC 8176 AMR claim mapping to TwoFactorStatus (MFA-grade list).
 *   - OIDC Core §5.1.1.1 ACR claim mapping (LoA / well-known URNs).
 *   - Edge cases that pwd / kba / face / geo alone are NOT MFA-grade.
 *
 * Each vector cites its RFC section in the test or data-provider name.
 */
#[CoversClass(OAuth2TokenResolver::class)]
#[CoversClass(PkceChallenge::class)]
final class Rfc6749ConformanceTest extends TestCase
{
    /**
     * RFC 7636 §A.1: the canonical PKCE S256 example. Any conformant
     * implementation must produce the same challenge from the same
     * verifier — this is the interop floor.
     *
     * verifier   = dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk
     * challenge  = E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM
     */
    #[Test]
    public function rfc7636PkceS256CanonicalVector(): void
    {
        $verifier = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
        $expected = 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM';

        $computed = self::base64UrlEncode(hash('sha256', $verifier, true));

        self::assertSame(
            $expected,
            $computed,
            'RFC 7636 §A.1 canonical PKCE S256 vector must match exactly',
        );
    }

    /**
     * PkceChallenge::generate() must produce a verifier/challenge pair
     * whose challenge is the base64url(SHA-256(verifier)) — i.e. the
     * S256 method per RFC 7636 §4.2.
     */
    #[Test]
    public function pkceGenerateProducesS256ConformantPair(): void
    {
        $pair = PkceChallenge::generate();

        self::assertSame('S256', $pair->method);
        // Verifier: base64url, 43 chars (32 bytes raw → 43 b64u chars).
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $pair->verifier);

        // Challenge must equal base64url(SHA-256(verifier)).
        $expected = self::base64UrlEncode(hash('sha256', $pair->verifier, true));
        self::assertSame($expected, $pair->challenge);
    }

    /**
     * RFC 8176 §2 catalogue: AMR values that ASSERT multi-factor
     * authentication. The resolver must map each of these to
     * `TwoFactorStatus::Verified`.
     *
     * @return iterable<string, array{0: list<string>}>
     */
    public static function mfaGradeAmrValues(): iterable
    {
        yield 'mfa (composite assertion)'        => [['mfa']];
        yield 'otp (one-time password)'          => [['otp']];
        yield 'hwk (hardware-secured key)'       => [['hwk']];
        yield 'swk (software-secured key)'       => [['swk']];
        yield 'fido (FIDO U2F / FIDO2)'          => [['fido']];
        yield 'fpt (fingerprint biometric)'      => [['fpt']];
        yield 'iris (iris biometric)'            => [['iris']];
        yield 'sms (PCI 8.3.1 deprecated)'       => [['sms']];
        yield 'composite pwd + otp'              => [['pwd', 'otp']];
        yield 'composite pwd + fido'             => [['pwd', 'fido']];
    }

    #[Test]
    #[DataProvider('mfaGradeAmrValues')]
    public function amrMfaGradeMapsToVerified(array $amr): void
    {
        $identity = $this->resolveWithClaims(['amr' => $amr]);

        self::assertNotNull($identity);
        self::assertSame(
            TwoFactorStatus::Verified,
            $identity->twoFactorStatus(),
            'AMR ' . implode(',', $amr) . ' must map to Verified per RFC 8176',
        );
    }

    /**
     * RFC 8176 §2: AMR values that are NOT MFA-grade in isolation.
     * pwd, kba, face, geo alone do not satisfy NIST 800-63 AAL2 /
     * PCI-DSS 8.3.1 — the resolver must fall closed to Disabled.
     *
     * @return iterable<string, array{0: list<string>}>
     */
    public static function nonMfaGradeAmrValues(): iterable
    {
        yield 'pwd alone'                  => [['pwd']];
        yield 'kba alone (knowledge-based)' => [['kba']];
        yield 'face alone'                 => [['face']];
        yield 'geo alone'                  => [['geo']];
        yield 'rba alone (risk-based)'     => [['rba']];
        yield 'empty amr array'            => [[]];
    }

    #[Test]
    #[DataProvider('nonMfaGradeAmrValues')]
    public function amrNonMfaGradeMapsToDisabled(array $amr): void
    {
        $identity = $this->resolveWithClaims(['amr' => $amr]);

        self::assertNotNull($identity);
        self::assertSame(
            TwoFactorStatus::Disabled,
            $identity->twoFactorStatus(),
            'AMR ' . implode(',', $amr) . ' must map to Disabled (not MFA-grade)',
        );
    }

    /**
     * OIDC Core §5.1.1.1: ACR claim mapping. Integer LoA ≥ 2 and
     * recognised MFA URNs map to Verified.
     *
     * @return iterable<string, array{0: string}>
     */
    public static function mfaGradeAcrValues(): iterable
    {
        yield 'LoA 2'                            => ['2'];
        yield 'LoA 3'                            => ['3'];
        yield 'LoA 4'                            => ['4'];
        yield 'incommon silver'                  => ['urn:mace:incommon:iap:silver'];
        yield 'incommon gold'                    => ['urn:mace:incommon:iap:gold'];
        yield 'OIDC Federation MFA'              => ['urn:openidfederation:auth:method:mfa'];
        yield 'PAPE multi-factor'                => ['http://schemas.openid.net/pape/policies/2007/06/multi-factor'];
        yield 'PAPE multi-factor-physical'       => ['http://schemas.openid.net/pape/policies/2007/06/multi-factor-physical'];
    }

    #[Test]
    #[DataProvider('mfaGradeAcrValues')]
    public function acrMfaGradeMapsToVerified(string $acr): void
    {
        $identity = $this->resolveWithClaims(['acr' => $acr]);

        self::assertNotNull($identity);
        self::assertSame(
            TwoFactorStatus::Verified,
            $identity->twoFactorStatus(),
            "ACR \"$acr\" must map to Verified",
        );
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function nonMfaGradeAcrValues(): iterable
    {
        yield 'LoA 1'         => ['1'];
        yield 'LoA 0'         => ['0'];
        yield 'unknown URN'   => ['urn:unknown:auth:method:bizarre'];
        yield 'empty string'  => [''];
        yield 'plain word'    => ['password'];
    }

    #[Test]
    #[DataProvider('nonMfaGradeAcrValues')]
    public function acrNonMfaGradeMapsToDisabled(string $acr): void
    {
        $identity = $this->resolveWithClaims(['acr' => $acr]);

        self::assertNotNull($identity);
        self::assertSame(
            TwoFactorStatus::Disabled,
            $identity->twoFactorStatus(),
            "ACR \"$acr\" must map to Disabled",
        );
    }

    /**
     * Fail-closed contract: claims with NO amr/acr present must default
     * to Disabled (forcing step-up middleware to challenge).
     */
    #[Test]
    public function missingAmrAndAcrDefaultsToDisabled(): void
    {
        $identity = $this->resolveWithClaims([]);

        self::assertNotNull($identity);
        self::assertSame(TwoFactorStatus::Disabled, $identity->twoFactorStatus());
    }

    /**
     * @param array<string, mixed> $claims
     */
    private function resolveWithClaims(array $claims): ?\Pulsar\Auth\Identity\IdentityInterface
    {
        $token = new AccessToken(
            id: 'tok-1',
            clientId: 'client-1',
            subjectId: 'user-42',
            scopes: ['openid', 'profile'],
            expiresAt: new DateTimeImmutable('+1 hour'),
            issuedAt: new DateTimeImmutable(),
            revoked: false,
            tokenValue: 'opaque-token-value',
        );

        $repo = new class($token) implements AccessTokenRepositoryInterface {
            public function __construct(private AccessToken $token) {}

            public function persist(AccessToken $token): void {}

            public function introspect(string $tokenValue): ?AccessToken
            {
                return $this->token;
            }

            public function revoke(string $tokenId): void {}

            public function revokeBySubject(string $subjectId): void {}

            public function isRevoked(string $tokenId): bool
            {
                return false;
            }
        };

        $claimsProvider = new class($claims) implements UserClaimsProviderInterface {
            /** @param array<string, mixed> $claims */
            public function __construct(private array $claims) {}

            /**
             * @param list<string> $scopes
             * @return array<string, mixed>
             */
            #[Override]
            public function getClaims(string $subjectId, array $scopes): array
            {
                return $this->claims;
            }

            #[Override]
            public function getSubjectIdentifier(string $userId, string $clientId): string
            {
                return $userId;
            }
        };

        $resolver = new OAuth2TokenResolver($repo, $claimsProvider);

        return $resolver->resolve('opaque-token-value');
    }

    private static function base64UrlEncode(string $data): string
    {
        return rtrim(str_replace(['+', '/'], ['-', '_'], base64_encode($data)), '=');
    }
}
