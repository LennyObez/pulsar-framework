<?php

declare(strict_types=1);

namespace Pulsar\Config;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Support\Coerce;

/**
 * Typed configuration DTO for audit logging settings.
 *
 * Maps from the `audit` key of `config/observability.php`.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class AuditConfig
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
     * @param array{
     *     enabled?: bool|int|string,
     *     log_path?: string,
     *     events?: list<string>,
     * } $data Raw `audit` sub-array from config/observability.php
     */
    #[NoDiscard]
    public static function fromArray(array $data, Environment $environment): self
    {
        $logPathEnv = $environment->get('AUDIT_LOG_PATH');

        return new self(
            enabled: (bool) ($data['enabled'] ?? true),
            logPath: $logPathEnv ?? Coerce::string($data['log_path'] ?? null, 'var/logs/audit.jsonl'),
            events: Coerce::listOfString($data['events'] ?? null),
        );
    }
}
