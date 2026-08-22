<?php

declare(strict_types=1);

namespace Pulsar\Extension\Studio\Console\Event\Payload;

use Pulsar\Api\Internal;
use Pulsar\Extension\Studio\Console\Event\ConsoleEvent;
use Pulsar\Extension\Studio\Console\Event\EventType;
use Pulsar\Extension\Studio\Console\Event\EventVersion;

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
    /**
     * @param string $sql Normalized SQL query
     * @param string $sqlFingerprint Query fingerprint for grouping
     * @param string $connectionName Database connection name
     * @param float $durationMs Query execution time in milliseconds
     * @param int|null $rowCount Number of rows returned/affected
     * @param string $queryType Query type (SELECT, INSERT, UPDATE, DELETE)
     * @param string|null $sqlRaw Raw SQL (only in Local env with store_raw_sql)
     * @param string|null $callSiteFile PHP file that triggered the query
     * @param int|null $callSiteLine Line number in the file
     * @param string|null $callSiteClass Class name that triggered the query
     * @param string|null $callSiteMethod Method name that triggered the query
     */
    public function __construct(
        public string $sql,
        public string $sqlFingerprint,
        public string $connectionName,
        public float $durationMs,
        public ?int $rowCount,
        public string $queryType,
        public ?string $sqlRaw = null,
        public ?string $callSiteFile = null,
        public ?int $callSiteLine = null,
        public ?string $callSiteClass = null,
        public ?string $callSiteMethod = null,
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
            'call_site_file' => $this->callSiteFile,
            'call_site_line' => $this->callSiteLine,
            'call_site_class' => $this->callSiteClass,
            'call_site_method' => $this->callSiteMethod,
        ];
    }
}
