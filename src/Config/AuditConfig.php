<?php

declare(strict_types=1);

namespace Pulsar\Config;

/**
 * Typed configuration DTO for audit logging settings.
 *
 * Maps from the `audit` key of `config/observability.php`.
 */
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
    public static function fromArray(array $data, Environment $environment): self
    {
        $enabled = (bool) ($data['enabled'] ?? true);

        $logPath = $environment->get('AUDIT_LOG_PATH')
            ?? (string) ($data['log_path'] ?? 'var/logs/audit.jsonl'); // @phpstan-ignore cast.string

        /** @var list<string> $events */
        $events = $data['events'] ?? [];

        return new self(
            enabled: $enabled,
            logPath: $logPath,
            events: $events,
        );
    }
}
