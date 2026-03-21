<?php

declare(strict_types=1);

namespace Pulsar\Security\Waf;

use NoDiscard;
use Pulsar\Api\Api;

use function is_array;
use function is_int;
use function is_string;

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
        /** @var list<string> $bypassIps */
        $bypassIps = isset($data['bypass_ips']) && is_array($data['bypass_ips'])
            ? $data['bypass_ips']
            : [];

        return new self(
            enabled: (bool) ($data['enabled'] ?? true),
            paranoiaLevel: isset($data['paranoia_level']) && is_int($data['paranoia_level'])
                ? max(1, min(4, $data['paranoia_level']))
                : 1,
            bypassIps: $bypassIps,
            customRulesPath: isset($data['custom_rules_path']) && is_string($data['custom_rules_path'])
                ? $data['custom_rules_path']
                : null,
        );
    }
}
