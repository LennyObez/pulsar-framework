<?php

declare(strict_types=1);

namespace Pulsar\Config;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\FeatureFlag\FlagStorageDriver;

/**
 * Typed configuration DTO for `config/features.php`.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class FeatureFlagConfig implements ReportsUnknownKeys
{
    /** Keys recognised in config/features.php. */
    private const array KNOWN_KEYS = ['enabled', 'storage', 'file_path', 'audit_evaluations', 'default_state', 'flags'];

    /**
     * @param array<string, array<string, mixed>> $flags Pre-configured flag definitions
     */
    public function __construct(
        public bool $enabled = false,
        public FlagStorageDriver $storage = FlagStorageDriver::Memory,
        public string $filePath = 'var/flags/flags.json',
        public bool $auditEvaluations = false,
        public bool $defaultState = false,
        public array $flags = [],
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
     *     storage?: string,
     *     file_path?: string,
     *     audit_evaluations?: bool|int|string,
     *     default_state?: bool|int|string,
     *     flags?: array<string, array<string, mixed>>,
     * } $data Raw array from config/features.php
     */
    #[NoDiscard]
    public static function fromArray(array $data, Environment $environment): self
    {
        $enabled = $environment->get('FEATURE_FLAGS_ENABLED') !== null
            ? $environment->get('FEATURE_FLAGS_ENABLED') === 'true'
            : (bool) ($data['enabled'] ?? false);

        return new self(
            enabled: $enabled,
            storage: FlagStorageDriver::from($data['storage'] ?? 'memory'),
            filePath: $data['file_path'] ?? 'var/flags/flags.json',
            auditEvaluations: (bool) ($data['audit_evaluations'] ?? false),
            defaultState: (bool) ($data['default_state'] ?? false),
            flags: $data['flags'] ?? [],
            unknownKeys: UnknownKeys::collect($data, self::KNOWN_KEYS),
        );
    }
}
