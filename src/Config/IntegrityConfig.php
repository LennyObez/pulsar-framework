<?php

declare(strict_types=1);

namespace Pulsar\Config;

use NoDiscard;
use Pulsar\Api\Api;

use function is_string;

/**
 * Typed configuration DTO for `config/integrity.php`.
 */
#[Api(since: '1.0.0')]
readonly class IntegrityConfig
{
    /**
     * @param list<string> $include Glob patterns for files to include
     * @param list<string> $exclude Glob patterns for files to exclude
     */
    public function __construct(
        public bool $enabled = false,
        public string $manifestPath = 'var/integrity/manifest.json',
        public IntegrityPolicyMode $mode = IntegrityPolicyMode::Warn,
        public array $include = ['src/**/*.php', 'config/**/*.php', 'bin/*'],
        public array $exclude = ['vendor/**', 'var/**', 'node_modules/**', '.git/**'],
    ) {}

    /**
     * @param array<string, mixed> $data Raw array from config/integrity.php
     */
    #[NoDiscard]
    public static function fromArray(array $data, Environment $environment): self
    {
        $enabled = $environment->get('INTEGRITY_ENABLED') !== null
            ? $environment->get('INTEGRITY_ENABLED') === 'true'
            : (bool) ($data['enabled'] ?? false);

        $modeValue = $data['mode'] ?? 'warn';
        $mode = IntegrityPolicyMode::from(is_string($modeValue) ? $modeValue : 'warn');

        /** @var list<string> $include */
        $include = $data['include'] ?? ['src/**/*.php', 'config/**/*.php', 'bin/*'];

        /** @var list<string> $exclude */
        $exclude = $data['exclude'] ?? ['vendor/**', 'var/**', 'node_modules/**', '.git/**'];

        return new self(
            enabled: $enabled,
            manifestPath: isset($data['manifest_path']) && is_string($data['manifest_path']) ? $data['manifest_path'] : 'var/integrity/manifest.json',
            mode: $mode,
            include: $include,
            exclude: $exclude,
        );
    }
}
