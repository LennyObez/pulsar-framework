<?php

declare(strict_types=1);

namespace Pulsar\DataProtection;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Config\ReportsUnknownKeys;
use Pulsar\Config\UnknownKeys;

use function is_array;

/**
 * Typed configuration DTO for data protection settings.
 *
 * Maps from the `config/data_protection.php` file. Covers retention
 * policies, purge settings, and consent tracking configuration.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class DataProtectionConfig implements ReportsUnknownKeys
{
    /** Keys read from config/data_protection.php. */
    private const array KNOWN_KEYS = ['retention', 'purge', 'consent'];

    /**
     * @param list<RetentionPolicy> $retention
     * @param PurgeConfig $purge
     * @param ConsentConfig $consent
     * @param list<string> $unknownKeys Keys present in config/data_protection.php that
     *     this DTO does not read — a misspelled `retention` silently leaves the purge
     *     orchestrator with no policies, so GDPR-mandated expiry never runs.
     */
    public function __construct(
        public array $retention = [],
        public PurgeConfig $purge = new PurgeConfig(),
        public ConsentConfig $consent = new ConsentConfig(),
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
     * @param array<string, mixed> $data Raw config array from data_protection.php
     */
    #[NoDiscard]
    public static function fromArray(array $data): self
    {
        $retention = [];
        /** @var mixed $rawRetention */
        $rawRetention = $data['retention'] ?? null;
        if (is_array($rawRetention)) {
            /** @var mixed $entry */
            foreach ($rawRetention as $entry) {
                if (is_array($entry)) {
                    /** @var array<string, mixed> $entry */
                    $retention[] = RetentionPolicy::fromArray($entry);
                }
            }
        }

        /** @var mixed $purge */
        $purge = $data['purge'] ?? null;
        /** @var array<string, mixed> $purgeArr */
        $purgeArr = is_array($purge) ? $purge : [];
        /** @var mixed $consent */
        $consent = $data['consent'] ?? null;
        /** @var array<string, mixed> $consentArr */
        $consentArr = is_array($consent) ? $consent : [];

        $purgeConfig = PurgeConfig::fromArray($purgeArr);
        $consentConfig = ConsentConfig::fromArray($consentArr);

        return new self(
            retention: $retention,
            purge: $purgeConfig,
            consent: $consentConfig,
            unknownKeys: [
                ...UnknownKeys::collect($data, self::KNOWN_KEYS),
                ...UnknownKeys::nested('purge', $purgeConfig),
                ...UnknownKeys::nested('consent', $consentConfig),
            ],
        );
    }
}
