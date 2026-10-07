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
final readonly class AuditConfig implements ReportsUnknownKeys
{
    /** Keys read from the `audit` sub-array of config/observability.php. */
    private const array KNOWN_KEYS = ['enabled', 'log_path', 'events'];

    /**
     * @param bool              $enabled    Whether audit logging is enabled
     * @param string            $logPath    File path for audit log output
     * @param list<string>      $events     Which event types to audit
     * @param list<string> $unknownKeys Keys present in the raw `audit` array that this
     *        DTO does not read — a misspelled `events` entry key silently narrows what
     *        is audited, which is the one thing an audit log must not do quietly.
     */
    public function __construct(
        public bool $enabled = true,
        public string $logPath = 'var/logs/audit.jsonl',
        public array $events = [],
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
            unknownKeys: UnknownKeys::collect($data, self::KNOWN_KEYS),
        );
    }
}
