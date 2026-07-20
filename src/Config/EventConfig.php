<?php

declare(strict_types=1);

namespace Pulsar\Config;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * Typed configuration DTO for `config/event.php`.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class EventConfig implements ReportsUnknownKeys
{
    /** Keys recognised in config/event.php. */
    private const array KNOWN_KEYS = ['enabled', 'storm_protection'];

    public function __construct(
        public bool $enabled = true,
        public StormProtectionConfig $stormProtection = new StormProtectionConfig(),
        /** @var list<string> */
        public array $unknownKeys = [],
    ) {}

    /**
     * @return list<string>
     */
    public function unknownConfigKeys(): array
    {
        return $this->unknownKeys;
    }

    /**
     * @param array{
     *     enabled?: bool|int|string,
     *     storm_protection?: array{
     *         max_depth?: int,
     *         loop_detection?: bool,
     *         max_repeats_per_event?: int,
     *     },
     * } $data Raw array from config/event.php
     */
    #[NoDiscard]
    public static function fromArray(array $data, Environment $environment): self
    {
        $enabled = $environment->get('EVENT_ENABLED') !== null
            ? $environment->get('EVENT_ENABLED') === 'true'
            : (bool) ($data['enabled'] ?? true);

        return new self(
            enabled: $enabled,
            stormProtection: StormProtectionConfig::fromArray($data['storm_protection'] ?? [], $environment),
            unknownKeys: UnknownKeys::collect($data, self::KNOWN_KEYS),
        );
    }
}
