<?php

declare(strict_types=1);

namespace Pulsar\Marketplace;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Extensibility\TrustTier;

use function is_bool;
use function is_int;
use function is_string;

/**
 * Configuration for the extension marketplace.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class MarketplaceConfig
{
    /**
     * @param string $registryUrl URL of the marketplace registry API
     * @param bool $autoUpdate Whether to auto-update extensions
     * @param TrustTier $minimumTrustTier Minimum trust tier to allow installation
     * @param bool $verifySignatures Whether to verify extension signatures
     * @param int $cacheLifetimeSeconds How long to cache registry data
     */
    public function __construct(
        public string $registryUrl = 'https://marketplace.pulsarphp.com/api/v1',
        public bool $autoUpdate = false,
        public TrustTier $minimumTrustTier = TrustTier::Community,
        public bool $verifySignatures = true,
        public int $cacheLifetimeSeconds = 3600,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        $trustTierValue = is_string($data['minimum_trust_tier'] ?? null)
            ? $data['minimum_trust_tier'] : '';

        return new self(
            registryUrl: is_string($data['registry_url'] ?? null)
                ? $data['registry_url']
                : 'https://marketplace.pulsarphp.com/api/v1',
            autoUpdate: is_bool($data['auto_update'] ?? null)
                ? $data['auto_update']
                : false,
            minimumTrustTier: TrustTier::tryFrom($trustTierValue) ?? TrustTier::Community,
            verifySignatures: is_bool($data['verify_signatures'] ?? null)
                ? $data['verify_signatures']
                : true,
            cacheLifetimeSeconds: is_int($data['cache_lifetime'] ?? null)
                ? $data['cache_lifetime']
                : 3600,
        );
    }
}
