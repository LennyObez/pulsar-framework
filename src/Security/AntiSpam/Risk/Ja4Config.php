<?php

declare(strict_types=1);

namespace Pulsar\Security\AntiSpam\Risk;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Support\Coerce;

use function array_filter;
use function array_values;
use function is_array;
use function is_string;

/**
 * Configuration for the JA4/JA4+ TLS-fingerprint risk signal.
 *
 * The application layer cannot compute a JA4 fingerprint (it has no access to
 * the raw TLS ClientHello), so the fingerprint must be computed at the
 * TLS-terminating edge / reverse proxy and forwarded in $headerName. Because a
 * client connecting directly could spoof that header, it is honoured only for
 * requests arriving through a trusted proxy ($trustedProxiesOnly, on by
 * default — requires DeployConfig::$trustedProxies to be configured).
 *
 * Known-bad fingerprints are operator-supplied (e.g. from a threat feed)
 * rather than hardcoded, since JA4 values shift with TLS-stack versions and a
 * baked-in list would rot.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class Ja4Config
{
    /**
     * @param list<string> $knownBadFingerprints Exact JA4 strings treated as malicious
     */
    public function __construct(
        public bool $enabled = false,
        public string $headerName = 'X-JA4',
        public bool $trustedProxiesOnly = true,
        public array $knownBadFingerprints = [],
        public float $matchScore = 0.9,
    ) {}

    /**
     * @param array{
     *     enabled?: bool|int|string,
     *     header_name?: string,
     *     trusted_proxies_only?: bool|int|string,
     *     known_bad_fingerprints?: list<string>,
     *     match_score?: float|int|string,
     * } $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        $fingerprints = $data['known_bad_fingerprints'] ?? null;

        return new self(
            enabled: Coerce::strictBool($data['enabled'] ?? null),
            headerName: Coerce::string($data['header_name'] ?? null, 'X-JA4'),
            trustedProxiesOnly: Coerce::strictBool($data['trusted_proxies_only'] ?? null, true),
            knownBadFingerprints: is_array($fingerprints) ? array_values(array_filter($fingerprints, is_string(...))) : [],
            matchScore: Coerce::nullableFloat($data['match_score'] ?? null) ?? 0.9,
        );
    }
}
