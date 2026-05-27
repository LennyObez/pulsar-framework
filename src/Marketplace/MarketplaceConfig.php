<?php

declare(strict_types=1);

namespace Pulsar\Marketplace;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Extensibility\TrustTier;
use Pulsar\Support\Coerce;

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
     * @param array{
     *     registry_url?: string,
     *     auto_update?: bool,
     *     minimum_trust_tier?: string,
     *     verify_signatures?: bool,
     *     cache_lifetime?: int,
     * } $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        return new self(
            registryUrl: Coerce::string($data['registry_url'] ?? null, 'https://marketplace.pulsarphp.com/api/v1'),
            autoUpdate: Coerce::strictBool($data['auto_update'] ?? null),
            minimumTrustTier: TrustTier::tryFrom(Coerce::string($data['minimum_trust_tier'] ?? null)) ?? TrustTier::Community,
            verifySignatures: Coerce::strictBool($data['verify_signatures'] ?? null, true),
            cacheLifetimeSeconds: Coerce::int($data['cache_lifetime'] ?? null, 3600),
        );
    }
}
