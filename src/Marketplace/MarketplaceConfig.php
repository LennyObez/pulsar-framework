<?php

declare(strict_types=1);

namespace Pulsar\Marketplace;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Config\Exception\ConfigException;
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
     * @param bool $verifySignatures Must be false. No installer, loader or verifier in the
     *                               framework checks an extension signature, so `true` is
     *                               refused rather than recorded as an enabled control
     * @param int $cacheLifetimeSeconds How long to cache registry data
     *
     * @throws ConfigException If $verifySignatures is true
     */
    public function __construct(
        public string $registryUrl = 'https://marketplace.pulsarphp.com/api/v1',
        public bool $autoUpdate = false,
        public TrustTier $minimumTrustTier = TrustTier::Community,
        public bool $verifySignatures = false,
        public int $cacheLifetimeSeconds = 3600,
    ) {
        if ($verifySignatures) {
            throw self::signatureVerificationUnavailable();
        }
    }

    /**
     * @param array{
     *     registry_url?: string,
     *     auto_update?: bool,
     *     minimum_trust_tier?: string,
     *     verify_signatures?: bool,
     *     cache_lifetime?: int,
     * } $data
     *
     * @throws ConfigException If `verify_signatures` is present and is anything but false
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        $verifySignatures = $data['verify_signatures'] ?? false;

        // Judged on the raw value rather than a coerced one: `verify_signatures: 1` would
        // coerce to false and hand the operator silence where they asked for a control.
        if ($verifySignatures !== false) {
            throw self::signatureVerificationUnavailable();
        }

        return new self(
            registryUrl: Coerce::string($data['registry_url'] ?? null, 'https://marketplace.pulsarphp.com/api/v1'),
            autoUpdate: Coerce::strictBool($data['auto_update'] ?? null),
            minimumTrustTier: TrustTier::tryFrom(Coerce::string($data['minimum_trust_tier'] ?? null)) ?? TrustTier::Community,
            verifySignatures: $verifySignatures,
            cacheLifetimeSeconds: Coerce::int($data['cache_lifetime'] ?? null, 3600),
        );
    }

    #[NoDiscard]
    private static function signatureVerificationUnavailable(): ConfigException
    {
        return ConfigException::invalidValue(
            'marketplace.verify_signatures',
            'extension signatures are verified nowhere in the framework, so enabling this would '
            . 'record a check that never runs. Remove the key until a verifier ships.',
        );
    }
}
