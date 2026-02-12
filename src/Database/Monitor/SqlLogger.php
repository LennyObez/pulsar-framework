<?php

declare(strict_types=1);

namespace Pulsar\Database\Monitor;

use Override;
use Psr\Log\LoggerInterface;
use Pulsar\Api\Api;

use function getenv;
use function hash;
use function json_encode;
use function preg_replace;

/**
 * Safe SQL logger that never exposes raw query values in production.
 *
 * Logs normalized SQL (values replaced with placeholders), a binding hash
 * for correlation, query duration, row count, and classification. Raw
 * bindings are only logged when explicitly enabled with environment
 * confirmation and PII values remain masked even then.
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
        $bindingHash = hash('xxh128', (string) json_encode($bindings));
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
     * Normalize SQL by replacing literal values with placeholders.
     *
     * If the SQL already uses `?` or `:named` placeholders, it is returned as-is.
     */
    private static function normalizeSql(string $sql): string
    {
        if (str_contains($sql, '?') || (bool) preg_match('/:\w+/', $sql)) {
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
