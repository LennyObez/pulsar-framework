<?php

declare(strict_types=1);

namespace Pulsar\Config;

use NoDiscard;
use Pulsar\Api\Api;

use function is_array;
use function is_string;

/**
 * Typed configuration DTO for `config/repl.php`.
 */
#[Api(since: '1.0.0')]
readonly class ReplConfig
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
     * @param array<string, mixed> $data Raw array from config/repl.php
     */
    #[NoDiscard]
    public static function fromArray(array $data, Environment $environment): self
    {
        $enabled = $environment->get('REPL_ENABLED') !== null
            ? $environment->get('REPL_ENABLED') === 'true'
            : (bool) ($data['enabled'] ?? false);

        $historyFile = $data['history_file'] ?? '.pulsar_repl_history';

        /** @var list<string> $startupCommands */
        $startupCommands = is_array($data['startup_commands'] ?? null) ? $data['startup_commands'] : [];

        return new self(
            enabled: $enabled,
            safeMode: (bool) ($data['safe_mode'] ?? true),
            audit: (bool) ($data['audit'] ?? false),
            historyFile: is_string($historyFile) ? $historyFile : '.pulsar_repl_history',
            startupCommands: $startupCommands,
        );
    }
}
