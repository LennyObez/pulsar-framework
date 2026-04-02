<?php

declare(strict_types=1);

namespace Pulsar\DataProtection;

use NoDiscard;
use Pulsar\Api\Api;

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
     * @param array{
     *     retention?: list<array<string, mixed>>,
     *     purge?: array<string, mixed>,
     *     consent?: array<string, mixed>,
     * } $data Raw config array from data_protection.php
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        $retention = [];

        foreach ($data['retention'] ?? [] as $entry) {
            $retention[] = RetentionPolicy::fromArray($entry);
        }

        return new self(
            retention: $retention,
            purge: PurgeConfig::fromArray($data['purge'] ?? []),
            consent: ConsentConfig::fromArray($data['consent'] ?? []),
        );
    }
}
