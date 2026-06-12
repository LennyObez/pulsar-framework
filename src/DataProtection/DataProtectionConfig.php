<?php

declare(strict_types=1);

namespace Pulsar\DataProtection;

use NoDiscard;
use Pulsar\Api\Api;

use function is_array;

/**
 * Typed configuration DTO for data protection settings.
 *
 * Maps from the `config/data_protection.php` file. Covers retention
 * policies, purge settings, and consent tracking configuration.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class DataProtectionConfig
{
    /**
     * @param list<RetentionPolicy> $retention
     * @param PurgeConfig $purge
     * @param ConsentConfig $consent
     */
    public function __construct(
        public array $retention = [],
        public PurgeConfig $purge = new PurgeConfig(),
        public ConsentConfig $consent = new ConsentConfig(),
    ) {}

    /**
     * @param array<string, mixed> $data Raw config array from data_protection.php
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        $retention = [];
        $rawRetention = $data['retention'] ?? null;
        if (is_array($rawRetention)) {
            foreach ($rawRetention as $entry) {
                if (is_array($entry)) {
                    $retention[] = RetentionPolicy::fromArray($entry);
                }
            }
        }

        $purge = $data['purge'] ?? null;
        $consent = $data['consent'] ?? null;

        return new self(
            retention: $retention,
            purge: PurgeConfig::fromArray(is_array($purge) ? $purge : []),
            consent: ConsentConfig::fromArray(is_array($consent) ? $consent : []),
        );
    }
}
