<?php

declare(strict_types=1);

namespace Pulsar\Security\AntiSpam\Internal;

use Closure;
use Pulsar\Api\Internal;
use Pulsar\Cache\Application\TaggedCacheInterface;
use Pulsar\Security\AntiSpam\MxDeliverabilityResolverInterface;

use function bin2hex;
use function checkdnsrr;
use function sodium_crypto_generichash;
use function sprintf;
use function strtolower;
use function trim;

/**
 * Resolves MX deliverability through the platform DNS resolver, caching
 * positive and negative results in the tagged cache so a repeated submission
 * is not a fresh lookup every time.
 *
 * Fail-open detection: DNS lookups that simply return "no record" cannot, on
 * their own, be told apart from a resolver that is down (both surface as a
 * negative). So when the target resolves to nothing, we probe a name that is
 * guaranteed to resolve (example.com, reserved by RFC 2606); if even that
 * fails, the resolver is unreachable and we return null (unknown) rather than
 * a false "undeliverable". Unknown results are never cached, so a transient
 * outage does not poison the cache.
 */
#[Internal(reason: 'Use MxDeliverabilityResolverInterface')]
final readonly class SystemMxDeliverabilityResolver implements MxDeliverabilityResolverInterface
{
    private const string CACHE_TAG = 'antispam_mx';

    /**
     * example.com is reserved by RFC 2606 and guaranteed to resolve, so a
     * failed lookup for it means the resolver itself is unreachable.
     */
    private const string LIVENESS_PROBE = 'example.com';

    /**
     * @var Closure(string, string): bool DNS record-existence lookup ($domain, $type)
     */
    private Closure $lookup;

    /**
     * @param Closure(string, string): bool|null $lookup Overridable DNS probe (defaults to checkdnsrr)
     */
    public function __construct(
        private ?TaggedCacheInterface $cache = null,
        private int $cacheTtlSeconds = 86400,
        ?Closure $lookup = null,
    ) {
        $this->lookup = $lookup ?? static fn(string $domain, string $type): bool => checkdnsrr($domain, $type);
    }

    public function isDeliverable(string $domain): ?bool
    {
        $normalized = strtolower(trim($domain, ". \t\r\n"));

        if ($normalized === '') {
            return null;
        }

        $key = sprintf('antispam_mx.%s', bin2hex(sodium_crypto_generichash($normalized)));

        if ($this->cache !== null) {
            $cached = $this->cache->get($key);

            if ($cached === 'yes') {
                return true;
            }

            if ($cached === 'no') {
                return false;
            }
        }

        $deliverable = $this->lookupDeliverable($normalized);

        if ($deliverable === null) {
            // Resolver unreachable: fail open and do NOT cache the unknown.
            return null;
        }

        $this->cache?->set($key, $deliverable ? 'yes' : 'no', [self::CACHE_TAG], $this->cacheTtlSeconds);

        return $deliverable;
    }

    private function lookupDeliverable(string $domain): ?bool
    {
        if (($this->lookup)($domain, 'MX')) {
            return true;
        }

        // RFC 5321 §5.1: with no MX, an A/AAAA address is an implicit MX.
        if (($this->lookup)($domain, 'A') || ($this->lookup)($domain, 'AAAA')) {
            return true;
        }

        // No records for the target. Tell "domain has none" apart from "resolver
        // down" with a liveness probe on a name that must resolve.
        if (!($this->lookup)(self::LIVENESS_PROBE, 'A')) {
            return null;
        }

        return false;
    }
}
