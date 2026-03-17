<?php

declare(strict_types=1);

namespace Pulsar\Extension\Auth\OAuth2\Adapter;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Auth\Guard\TokenResolverInterface;
use Pulsar\Auth\Identity\Identity;
use Pulsar\Auth\Identity\IdentityInterface;
use Pulsar\Auth\Identity\TwoFactorStatus;
use Pulsar\Extension\Auth\OAuth2\Contract\AccessTokenRepositoryInterface;
use Pulsar\Extension\Auth\OAuth2\Contract\UserClaimsProviderInterface;

use function in_array;
use function is_array;
use function is_string;

/**
 * OAuth2 bearer token resolver for the auth guard system.
 *
 * Resolves OAuth2 access tokens (reference or JWT) to Pulsar identities.
 * Integrates with the existing TokenGuard via TokenResolverInterface.
 */
#[Internal(reason: 'Adapter implementation; use TokenResolverInterface')]
final readonly class OAuth2TokenResolver implements TokenResolverInterface
{
    /**
     * Authentication Method Reference values that assert multi-factor
     * authentication was performed at the IdP. Per RFC 8176 (OAuth 2.0
     * AMR Values), these are the canonical method names a relying party
     * can rely on to derive a "MFA verified" decision from an OAuth2
     * grant. The list intentionally covers MFA-grade factors only —
     * `pwd` / `kba` / `face` / `geo` are deliberately excluded because
     * they don't satisfy NIST 800-63 AAL2 / PCI-DSS 8.3.1 in isolation.
     */
    private const array MFA_AMR_VALUES = [
        'mfa',  // RFC 8176: any combination of two or more factors
        'otp',  // one-time password (RFC 8176 sec 2)
        'hwk',  // hardware-secured key
        'swk',  // software-secured key
        'fido', // FIDO U2F / FIDO2 / WebAuthn
        'fpt',  // fingerprint biometric
        'iris', // iris-scan biometric
        'sms',  // SMS-delivered second factor (PCI 8.3.1 deprecated but accepted)
    ];

    public function __construct(
        private AccessTokenRepositoryInterface $tokenRepository,
        private UserClaimsProviderInterface $claimsProvider,
    ) {}

    #[Override]
    public function resolve(string $token): ?IdentityInterface
    {
        $accessToken = $this->tokenRepository->introspect($token);

        if ($accessToken === null || !$accessToken->isActive()) {
            return null;
        }

        // Resolve basic profile claims for the identity
        $claims = $this->claimsProvider->getClaims($accessToken->subjectId, $accessToken->scopes);

        return new Identity(
            id: $accessToken->subjectId,
            displayName: is_string($claims['name'] ?? null) ? $claims['name'] : (is_string($claims['preferred_username'] ?? null) ? $claims['preferred_username'] : $accessToken->subjectId),
            roles: [],
            twoFactorStatus: self::deriveTwoFactorStatus($claims),
            attributes: [
                'oauth2_client_id' => $accessToken->clientId,
                'oauth2_scopes' => $accessToken->scopes,
                'oauth2_token_id' => $accessToken->id,
            ],
        );
    }

    /**
     * Derive the identity's 2FA status from OIDC `amr` / `acr` claims.
     *
     * F385.7: hardcoding `TwoFactorStatus::Disabled` here turned every
     * OAuth2-authenticated request into an effective 2FA bypass — a
     * route protected by `StepUpMiddleware` saw "two_factor=disabled"
     * regardless of how the underlying user actually authenticated at
     * the IdP. The fix reads the standard OIDC `amr` (authentication
     * method references, RFC 8176) and `acr` (authentication context
     * class reference, OIDC Core 5.1.1.1) claims and maps them onto
     * the framework's `TwoFactorStatus` enum:
     *
     * - `amr` containing any MFA-grade factor (mfa, otp, hwk, fido,
     *   fpt, iris, swk, sms) -> `Verified`.
     * - `acr` >= "2" or matching one of the well-known MFA URNs -> `Verified`.
     * - Anything else -> `Disabled` (the IdP did not assert MFA, so
     *   step-up routes will challenge the caller).
     *
     * Falling closed on `Disabled` rather than `Verified` is intentional:
     * an IdP that omits AMR/ACR claims is treated as "MFA not asserted"
     * and routes that require step-up will reject the token, forcing
     * a deliberate IdP configuration to enable MFA-bearing OAuth2 grants.
     *
     * @param array<string, mixed> $claims
     */
    private static function deriveTwoFactorStatus(array $claims): TwoFactorStatus
    {
        $amr = $claims['amr'] ?? null;

        if (is_array($amr)) {
            foreach ($amr as $method) {
                if (is_string($method) && in_array($method, self::MFA_AMR_VALUES, true)) {
                    return TwoFactorStatus::Verified;
                }
            }
        }

        $acr = $claims['acr'] ?? null;

        if (is_string($acr) && self::isMfaAcr($acr)) {
            return TwoFactorStatus::Verified;
        }

        return TwoFactorStatus::Disabled;
    }

    /**
     * Check whether an OIDC `acr` claim asserts MFA-grade authentication.
     *
     * Recognises:
     * - integer-string levels `"2"` and above (NIST 800-63 LoA convention);
     * - well-known URNs that map to AAL2 / IAL2 (incommon silver/gold,
     *   `urn:openidfederation:auth:method:mfa`, etc.).
     *
     * Unknown ACR strings fall through to `false` (treated as "not MFA"),
     * keeping the resolver fail-closed for unrecognised IdP profiles.
     */
    private static function isMfaAcr(string $acr): bool
    {
        // Integer LoA level: "2" or higher (NIST 800-63 / OIDC Core 5.1.1.1).
        if (ctype_digit($acr) && (int) $acr >= 2) {
            return true;
        }

        return in_array($acr, [
            'urn:mace:incommon:iap:silver',
            'urn:mace:incommon:iap:gold',
            'urn:openidfederation:auth:method:mfa',
            'http://schemas.openid.net/pape/policies/2007/06/multi-factor',
            'http://schemas.openid.net/pape/policies/2007/06/multi-factor-physical',
        ], true);
    }
}
