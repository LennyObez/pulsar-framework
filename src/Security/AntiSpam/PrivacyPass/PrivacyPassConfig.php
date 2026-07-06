<?php

declare(strict_types=1);

namespace Pulsar\Security\AntiSpam\PrivacyPass;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Security\AntiSpam\PrivacyPass\Internal\PrivateToken;
use Pulsar\Support\Coerce;

/**
 * Configuration for Private Access Token (Privacy Pass) verification at the Origin.
 *
 * Pulsar is the Origin: it advertises a token challenge and accepts redeemed
 * tokens as proof of a legitimate client, but it does not issue tokens. The
 * issuer's public key ($tokenKey, a base64url SPKI) and name ($issuerName) are
 * supplied by the operator out of band; a valid token signed under that key and
 * bound to this Origin bypasses the adaptive challenge.
 *
 * Verification is stateless (empty redemption_context), so a token is reusable
 * for the issuer key's lifetime — acceptable for a bypass signal, since
 * obtaining a token already required passing the attester. Per-request,
 * single-use binding would require server-side state and is out of scope here.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class PrivacyPassConfig
{
    public function __construct(
        public bool $enabled = false,
        public string $issuerName = '',
        public string $originInfo = '',
        public string $tokenKey = '',
        public int $tokenType = PrivateToken::TYPE_BLIND_RSA,
    ) {}

    /**
     * The fixed challenge this Origin advertises and verifies tokens against.
     */
    #[NoDiscard]
    public function challenge(): TokenChallenge
    {
        return new TokenChallenge($this->tokenType, $this->issuerName, $this->originInfo);
    }

    /**
     * Whether the configuration is complete enough to verify tokens.
     */
    #[NoDiscard]
    public function isUsable(): bool
    {
        return $this->enabled && $this->issuerName !== '' && $this->tokenKey !== '';
    }

    /**
     * @param array{
     *     enabled?: bool|int|string,
     *     issuer_name?: string,
     *     origin_info?: string,
     *     token_key?: string,
     * } $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        return new self(
            enabled: Coerce::strictBool($data['enabled'] ?? null),
            issuerName: Coerce::string($data['issuer_name'] ?? null, ''),
            originInfo: Coerce::string($data['origin_info'] ?? null, ''),
            tokenKey: Coerce::string($data['token_key'] ?? null, ''),
        );
    }
}
