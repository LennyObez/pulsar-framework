<?php

declare(strict_types=1);

namespace Pulsar\Config;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * Typed configuration DTO for `config/integrity.php`.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class IntegrityConfig
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
     * @param array{
     *     enabled?: bool|int|string,
     *     manifest_path?: string,
     *     mode?: string,
     *     include?: list<string>,
     *     exclude?: list<string>,
     * } $data Raw array from config/integrity.php
     */
    #[NoDiscard]
    public static function fromArray(array $data, Environment $environment): self
    {
        $enabled = $environment->get('INTEGRITY_ENABLED') !== null
            ? $environment->get('INTEGRITY_ENABLED') === 'true'
            : (bool) ($data['enabled'] ?? false);

        return new self(
            enabled: $enabled,
            manifestPath: $data['manifest_path'] ?? 'var/integrity/manifest.json',
            mode: IntegrityPolicyMode::from($data['mode'] ?? 'warn'),
            include: $data['include'] ?? ['src/**/*.php', 'config/**/*.php', 'bin/*'],
            exclude: $data['exclude'] ?? ['vendor/**', 'var/**', 'node_modules/**', '.git/**'],
        );
    }
}
