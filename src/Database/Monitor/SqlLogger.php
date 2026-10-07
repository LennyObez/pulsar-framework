<?php

declare(strict_types=1);

namespace Pulsar\Database\Monitor;

use JsonException;
use Override;
use Psr\Log\LoggerInterface;
use Pulsar\Api\Api;

use function getenv;
use function hash;
use function json_encode;
use function preg_replace;

use const JSON_THROW_ON_ERROR;

/**
 * Safe SQL logger that never exposes raw query values in production.
 *
 * Logs normalized SQL (values replaced with placeholders), a binding hash
 * for correlation, query duration, row count, and classification. Raw
 * bindings are only logged when explicitly enabled with environment
 * confirmation and PII values remain masked even then.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class SqlLogger implements SqlLoggerInterface
{
    private PiiMasker $piiMasker;

    public function __construct(
        private LoggerInterface $logger,
        private MonitorConfig $config,
    ) {
        $this->piiMasker = new PiiMasker($this->config->piiColumns);
    }

    /**
     * Log a SQL query execution.
     *
     * @param array<string|int, mixed> $bindings
     */
    #[Override]
    public function log(string $sql, array $bindings, float $durationMs, int $rowCount): void
    {
        $normalizedSql = self::normalizeSql($sql);
        $bindingHash = self::hashBindings($bindings);
        $classification = QueryClassifier::classify($sql);

        $entry = new SqlLogEntry(
            normalizedSql: $normalizedSql,
            bindingHash: $bindingHash,
            durationMs: $durationMs,
            rowCount: $rowCount,
            classification: $classification,
            sensitivityLevel: 'standard',
        );

        $context = [
            'sql' => $entry->normalizedSql,
            'binding_hash' => $entry->bindingHash,
            'duration_ms' => $entry->durationMs,
            'row_count' => $entry->rowCount,
            'classification' => $entry->classification->value,
            'sensitivity' => $entry->sensitivityLevel,
        ];

        if ($this->canLogRawBindings()) {
            $context['bindings'] = $this->piiMasker->mask($bindings);
        }

        $this->logger->debug('SQL query executed', $context);
    }

    /**
     * Compute a stable correlation hash for the bindings.
     *
     * `json_encode()` returns `false` for un-encodable input (non-UTF-8 binary
     * strings, resource handles, recursion). Casting that `false` to a string
     * yields `''`, so every query with un-encodable bindings would collapse to
     * the SAME hash, silently destroying correlation. Encoding with
     * `JSON_THROW_ON_ERROR` lets us detect that case and emit a distinct
     * sentinel hash instead of the constant empty-string hash.
     *
     * @param array<string|int, mixed> $bindings
     */
    private static function hashBindings(array $bindings): string
    {
        try {
            $encoded = json_encode($bindings, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return hash('xxh128', "\0unencodable-bindings\0");
        }

        return hash('xxh128', $encoded);
    }

    /**
     * Normalize SQL by replacing literal values with placeholders.
     *
     * If the SQL already uses `?` or `:named` placeholders, it is returned as-is.
     */
    private static function normalizeSql(string $sql): string
    {
        if (str_contains($sql, '?') || preg_match('/:\w+/', $sql) === 1) {
            return $sql;
        }

        $result = preg_replace("/('[^']*')/", '?', $sql);
        $result = preg_replace('/\b\d+(\.\d+)?\b/', '?', $result ?? $sql);

        return $result ?? $sql;
    }

    private function canLogRawBindings(): bool
    {
        if (!$this->config->logRawBindings) {
            return false;
        }

        if (!$this->config->requireEnvironmentConfirmation) {
            return false;
        }

        return getenv('DB_LOG_RAW_BINDINGS') === 'CONFIRM_UNSAFE';
    }
}
