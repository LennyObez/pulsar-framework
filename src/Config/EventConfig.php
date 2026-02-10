<?php

declare(strict_types=1);

namespace Pulsar\Config;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * Typed configuration DTO for `config/event.php`.
 */
#[Api(since: '1.0.0')]
final readonly class EventConfig
{
    public function __construct(
        public bool $enabled = true,
        public StormProtectionConfig $stormProtection = new StormProtectionConfig(),
    ) {}

    /**
     * @param array<string, mixed> $data Raw array from config/event.php
     */
    #[NoDiscard]
    public static function fromArray(array $data, Environment $environment): self
    {
        $enabled = $environment->get('EVENT_ENABLED') !== null
            ? $environment->get('EVENT_ENABLED') === 'true'
            : (bool) ($data['enabled'] ?? true);

        /** @var array<string, mixed> $stormData */
        $stormData = $data['storm_protection'] ?? [];

        return new self(
            enabled: $enabled,
            stormProtection: StormProtectionConfig::fromArray($stormData, $environment),
        );
    }
}
