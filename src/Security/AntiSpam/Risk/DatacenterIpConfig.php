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
 * Configuration for the datacenter-IP risk signal.
 *
 * Traffic from hosting/datacenter networks is more likely to be automated than
 * residential traffic. With no bundled ASN database (deliberately — no external
 * data dependency), the ranges are operator-supplied CIDRs (e.g. known abusive
 * ASNs or cloud providers exported as CIDR lists); a client IP within them
 * contributes $score to the engine.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class DatacenterIpConfig
{
    /**
     * @param list<string> $ranges Datacenter/hosting CIDR ranges
     */
    public function __construct(
        public bool $enabled = false,
        public array $ranges = [],
        public float $score = 0.5,
    ) {}

    /**
     * @param array{
     *     enabled?: bool|int|string,
     *     ranges?: list<string>,
     *     score?: float|int|string,
     * } $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        $ranges = $data['ranges'] ?? null;

        return new self(
            enabled: Coerce::strictBool($data['enabled'] ?? null),
            ranges: is_array($ranges) ? array_values(array_filter($ranges, is_string(...))) : [],
            score: Coerce::nullableFloat($data['score'] ?? null) ?? 0.5,
        );
    }
}
