<?php

declare(strict_types=1);

namespace Pulsar\Studio\Console\Event\Payload;

use Pulsar\Api\Internal;
use Pulsar\Studio\Console\Event\ConsoleEvent;
use Pulsar\Studio\Console\Event\EventType;
use Pulsar\Studio\Console\Event\EventVersion;

/**
 * Database query event payload.
 *
 * Stores normalized SQL by default. The optional sql_raw field
 * is only populated in Local environment when store_raw_sql is enabled.
 * Bound values are NEVER stored.
 */
#[Internal]
final readonly class DatabaseQueryPayload implements ConsoleEvent
{
    public function __construct(
        public string $sql,
        public string $sqlFingerprint,
        public string $connectionName,
        public float $durationMs,
        public ?int $rowCount,
        public string $queryType,
        public ?string $sqlRaw = null,
    ) {}

    public function eventType(): EventType
    {
        return EventType::DatabaseQuery;
    }

    public function schemaVersion(): EventVersion
    {
        return EventVersion::V1;
    }

    public function toArray(): array
    {
        return [
            'sql' => $this->sql,
            'sql_fingerprint' => $this->sqlFingerprint,
            'connection_name' => $this->connectionName,
            'duration_ms' => $this->durationMs,
            'row_count' => $this->rowCount,
            'query_type' => $this->queryType,
            'sql_raw' => $this->sqlRaw,
        ];
    }
}
