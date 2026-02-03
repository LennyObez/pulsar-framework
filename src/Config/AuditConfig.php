<?php

declare(strict_types=1);

namespace Pulsar\Config;

use function is_string;

use NoDiscard;
use Pulsar\Api\Api;

/**
 * Typed configuration DTO for audit logging settings.
 *
 * Maps from the `audit` key of `config/observability.php`.
 */
#[Api]
readonly class AuditConfig
{
    /**
     * @param bool              $enabled    Whether audit logging is enabled
     * @param string            $logPath    File path for audit log output
     * @param list<string>      $events     Which event types to audit
     */
    public function __construct(
        public bool $enabled = true,
        public string $logPath = 'var/logs/audit.jsonl',
        public array $events = [],
    ) {}

    /**
     * Build from the raw audit config array.
     *
     * @param array<string, mixed> $data Raw `audit` sub-array from config/observability.php
     */
    #[NoDiscard]
    public static function fromArray(array $data, Environment $environment): self
    {
        $enabled = (bool) ($data['enabled'] ?? true);

        $rawLogPath = $data['log_path'] ?? 'var/logs/audit.jsonl';
        $logPath = $environment->get('AUDIT_LOG_PATH')
            ?? (is_string($rawLogPath) ? $rawLogPath : 'var/logs/audit.jsonl');

        /** @var list<string> $events */
        $events = $data['events'] ?? [];

        return new self(
            enabled: $enabled,
            logPath: $logPath,
            events: $events,
        );
    }
}
