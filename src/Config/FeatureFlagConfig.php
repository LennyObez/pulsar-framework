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
final readonly class FeatureFlagConfig
{
    /**
     * @param array<string, array<string, mixed>> $flags Pre-configured flag definitions
     */
    public function __construct(
        public bool $enabled = false,
        public FlagStorageDriver $storage = FlagStorageDriver::Memory,
        public string $filePath = 'storage/flags.json',
        public bool $auditEvaluations = false,
        public bool $defaultState = false,
        public array $flags = [],
    ) {}

    /**
     * @param array<string, mixed> $data Raw array from config/features.php
     */
    #[NoDiscard]
    public static function fromArray(array $data, Environment $environment): self
    {
        $enabled = $environment->get('FEATURE_FLAGS_ENABLED') !== null
            ? $environment->get('FEATURE_FLAGS_ENABLED') === 'true'
            : (bool) ($data['enabled'] ?? false);

        /** @var string $storageValue */
        $storageValue = $data['storage'] ?? 'memory';

        /** @var array<string, array<string, mixed>> $flags */
        $flags = $data['flags'] ?? [];

        /** @var string $filePath */
        $filePath = $data['file_path'] ?? 'storage/flags.json';

        return new self(
            enabled: $enabled,
            storage: FlagStorageDriver::from($storageValue),
            filePath: $filePath,
            auditEvaluations: (bool) ($data['audit_evaluations'] ?? false),
            defaultState: (bool) ($data['default_state'] ?? false),
            flags: $flags,
        );
    }
}
