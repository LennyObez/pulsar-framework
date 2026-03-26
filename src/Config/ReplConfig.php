<?php

declare(strict_types=1);

namespace Pulsar\Config;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * Typed configuration DTO for `config/repl.php`.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class ReplConfig
{
    /**
     * @param list<string> $startupCommands
     */
    public function __construct(
        public bool $enabled = false,
        public bool $safeMode = true,
        public bool $audit = false,
        public string $historyFile = '.pulsar_repl_history',
        public array $startupCommands = [],
    ) {}

    /**
     * @param array{
     *     enabled?: bool|int|string,
     *     safe_mode?: bool|int|string,
     *     audit?: bool|int|string,
     *     history_file?: string,
     *     startup_commands?: list<string>,
     * } $data Raw array from config/repl.php
     */
    #[NoDiscard]
    public static function fromArray(array $data, Environment $environment): self
    {
        $enabled = $environment->get('REPL_ENABLED') !== null
            ? $environment->get('REPL_ENABLED') === 'true'
            : (bool) ($data['enabled'] ?? false);

        return new self(
            enabled: $enabled,
            safeMode: (bool) ($data['safe_mode'] ?? true),
            audit: (bool) ($data['audit'] ?? false),
            historyFile: $data['history_file'] ?? '.pulsar_repl_history',
            startupCommands: $data['startup_commands'] ?? [],
        );
    }
}
