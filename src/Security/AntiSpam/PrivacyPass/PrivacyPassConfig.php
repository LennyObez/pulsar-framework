<?php

declare(strict_types=1);

namespace Pulsar\Security\AntiSpam\PrivacyPass;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Security\AntiSpam\PrivacyPass\Internal\PrivateToken;
use Pulsar\Support\Coerce;

use function array_filter;
use function array_unshift;
use function array_values;
use function is_array;
use function is_string;

/**
 * Configuration for Private Access Token (Privacy Pass) verification at the Origin.
 *
 * Pulsar is the Origin: it advertises a token challenge and accepts redeemed
 * tokens as proof of a legitimate client, but it does not issue tokens. The
 * issuer's public key(s) (base64url SPKI) and name ($issuerName) are supplied by
 * the operator out of band — directly via $tokenKey/$tokenKeys, or discovered
 * from the issuer directory ($directoryUrl, RFC 9576). A valid token signed under
 * any configured key and bound to this Origin bypasses the adaptive challenge.
 *
 * Multiple keys support seamless key rotation (old and new keys both verify
 * during the overlap). Redemption is single-use by default: each token's nonce
 * is remembered in the cache so a captured token cannot be replayed (degrades to
 * reuse-within-lifetime, logged, when no cache is bound).
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class PrivacyPassConfig
{
    /**
     * @param list<string> $tokenKeys Additional issuer public keys (base64url SPKI) for rotation
     */
    public function __construct(
        public bool $enabled = false,
        public string $issuerName = '',
        public string $originInfo = '',
        public string $tokenKey = '',
        public array $tokenKeys = [],
        public string $directoryUrl = '',
        public bool $singleUse = true,
        public int $singleUseTtlSeconds = 86400,
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
     * All configured issuer keys (base64url SPKI), de-duplicated, the singular
     * $tokenKey first so it is advertised as the primary key.
     *
     * @return list<string>
     */
    #[NoDiscard]
    public function allTokenKeys(): array
    {
        $keys = $this->tokenKeys;
        if ($this->tokenKey !== '') {
            array_unshift($keys, $this->tokenKey);
        }

        return array_values(array_unique(array_filter($keys, static fn(string $k): bool => $k !== '')));
    }

    /**
     * Whether the configuration is complete enough to verify tokens.
     *
     * A directory URL alone is enough: keys are then discovered at runtime.
     */
    #[NoDiscard]
    public function isUsable(): bool
    {
        return $this->enabled
            && $this->issuerName !== ''
            && ($this->allTokenKeys() !== [] || $this->directoryUrl !== '');
    }

    /**
     * @param array{
     *     enabled?: bool|int|string,
     *     issuer_name?: string,
     *     origin_info?: string,
     *     token_key?: string,
     *     token_keys?: list<string>,
     *     directory_url?: string,
     *     single_use?: bool|int|string,
     *     single_use_ttl_seconds?: int|string,
     * } $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        $tokenKeys = $data['token_keys'] ?? null;

        return new self(
            enabled: Coerce::strictBool($data['enabled'] ?? null),
            issuerName: Coerce::string($data['issuer_name'] ?? null, ''),
            originInfo: Coerce::string($data['origin_info'] ?? null, ''),
            tokenKey: Coerce::string($data['token_key'] ?? null, ''),
            tokenKeys: is_array($tokenKeys) ? array_values(array_filter($tokenKeys, is_string(...))) : [],
            directoryUrl: Coerce::string($data['directory_url'] ?? null, ''),
            singleUse: Coerce::strictBool($data['single_use'] ?? null, true),
            singleUseTtlSeconds: Coerce::int($data['single_use_ttl_seconds'] ?? null, 86400),
        );
    }
}
