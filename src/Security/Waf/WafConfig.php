<?php

declare(strict_types=1);

namespace Pulsar\Security\Waf;

use NoDiscard;
use Pulsar\Api\Api;

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
     * @param array{
     *     enabled?: bool|int|string,
     *     paranoia_level?: int,
     *     bypass_ips?: list<string>,
     *     custom_rules_path?: string|null,
     * } $data
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        return new self(
            enabled: (bool) ($data['enabled'] ?? true),
            paranoiaLevel: max(1, min(4, $data['paranoia_level'] ?? 1)),
            bypassIps: $data['bypass_ips'] ?? [],
            customRulesPath: $data['custom_rules_path'] ?? null,
        );
    }
}
