<?php

declare(strict_types=1);

namespace Pulsar\Security\Waf;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Support\Coerce;

use function max;
use function min;

/**
 * WAF engine configuration.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class WafConfig
{
    /**
     * @param bool $enabled Whether the WAF is active
     * @param int $paranoiaLevel Paranoia level 1-4 (CRS convention)
     * @param list<string> $bypassIps Trusted IPs that bypass the WAF
     * @param string|null $customRulesPath Path to custom rule definitions
     */
    public function __construct(
        public bool $enabled = true,
        public int $paranoiaLevel = 1,
        public array $bypassIps = [],
        public ?string $customRulesPath = null,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        return new self(
            enabled: (bool) ($data['enabled'] ?? true),
            paranoiaLevel: max(1, min(4, Coerce::int($data['paranoia_level'] ?? null, 1))),
            bypassIps: Coerce::listOfString($data['bypass_ips'] ?? null),
            customRulesPath: Coerce::nullableString($data['custom_rules_path'] ?? null),
        );
    }
}
