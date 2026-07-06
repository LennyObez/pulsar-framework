<?php

declare(strict_types=1);

namespace Pulsar\Security\AntiSpam\PrivacyPass;

use NoDiscard;
use Pulsar\Api\Api;

use function base64_encode;
use function strtr;

/**
 * Builds the `WWW-Authenticate: PrivateToken` challenge value (RFC 9577 §2.1.2).
 *
 * The header advertises, to a Privacy Pass-capable client, the issuer it should
 * redeem against (the base64url TokenChallenge) and the issuer public key (the
 * base64url SPKI). The client redeems a token with that issuer and retries the
 * request carrying `Authorization: PrivateToken token=...`. Per RFC 9577 both
 * parameters are base64url *with* padding and are emitted as quoted-strings
 * because the padded encoding may contain '='.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class PrivacyPassChallengeIssuer
{
    public function __construct(
        private TokenChallenge $challenge,
        private string $tokenKeyDer,
    ) {}

    /**
     * The full `WWW-Authenticate` header value, e.g.
     * `PrivateToken challenge="...", token-key="..."`.
     */
    #[NoDiscard]
    public function headerValue(): string
    {
        return 'PrivateToken challenge="' . self::base64Url($this->challenge->encode())
            . '", token-key="' . self::base64Url($this->tokenKeyDer) . '"';
    }

    /**
     * Base64url with padding (RFC 4648 §5), as required for these auth-params.
     */
    private static function base64Url(string $value): string
    {
        return strtr(base64_encode($value), '+/', '-_');
    }
}
