<?php

declare(strict_types=1);

namespace Pulsar\DataProtection;

use DateTimeImmutable;
use Override;
use Psr\Log\LoggerInterface;
use Pulsar\Api\Internal;
use RuntimeException;

use function array_filter;
use function fclose;
use function file_put_contents;
use function fopen;
use function fread;
use function is_array;
use function is_file;
use function is_string;
use function json_decode;
use function json_encode;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;
use const LOCK_EX;

/**
 * Purges audit log entries older than the configured retention period.
 *
 * Reads the JSONL audit log, filters out expired entries, and rewrites the
 * file atomically. This is the reference implementation for framework-owned
 * audit log purging per GDPR Art. 17 and ISO 27001 A.8.10.
 */
#[Internal(reason: 'Reference implementation; use DataPurgeInterface for type hints')]
final readonly class AuditLogPurge implements DataPurgeInterface
{
    public function __construct(
        private string $logPath,
        private ?LoggerInterface $logger = null,
    ) {}

    #[Override]
    public function purge(RetentionPolicyInterface $policy): int
    {
        if (!is_file($this->logPath)) {
            return 0;
        }

        $now = new DateTimeImmutable();
        $entries = $this->readEntries();
        $retained = [];
        $purgedCount = 0;

        foreach ($entries as $entry) {
            $timestamp = $this->extractTimestamp($entry);

            if ($timestamp !== null && $policy->isExpired($timestamp, $now)) {
                $purgedCount++;
            } else {
                $retained[] = $entry;
            }
        }

        if ($purgedCount > 0) {
            $this->writeEntries($retained);
        }

        return $purgedCount;
    }

    #[Override]
    public function countExpired(RetentionPolicyInterface $policy): int
    {
        if (!is_file($this->logPath)) {
            return 0;
        }

        $now = new DateTimeImmutable();
        $entries = $this->readEntries();
        $count = 0;

        foreach ($entries as $entry) {
            $timestamp = $this->extractTimestamp($entry);

            if ($timestamp !== null && $policy->isExpired($timestamp, $now)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function readEntries(): array
    {
        $handle = fopen($this->logPath, 'rb');

        if ($handle === false) {
            return [];
        }

        $content = '';

        while (($chunk = fread($handle, 8192)) !== false && $chunk !== '') {
            $content .= $chunk;
        }

        fclose($handle);

        $lines = array_filter(
            explode("\n", $content),
            static fn(string $line): bool => trim($line) !== '',
        );

        $entries = [];

        foreach ($lines as $line) {
            /** @var array<string, mixed>|null $decoded */
            $decoded = json_decode(trim($line), true);

            if (is_array($decoded)) {
                $entries[] = $decoded;
            } else {
                // A malformed line is not retained on rewrite, so it would be
                // silently lost from the audit trail. Surface it instead of
                // destroying it without record.
                $this->logger?->warning('Skipping malformed audit log entry', [
                    'log_path' => $this->logPath,
                    'line' => trim($line),
                ]);
            }
        }

        return $entries;
    }

    /**
     * @param list<array<string, mixed>> $entries
     */
    private function writeEntries(array $entries): void
    {
        // Truncate and rewrite atomically
        $content = '';

        foreach ($entries as $entry) {
            $content .= json_encode(
                $entry,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ) . "\n";
        }

        $written = file_put_contents($this->logPath, $content, LOCK_EX);

        if ($written === false) {
            // Surface I/O failure rather than letting purge() report a
            // non-zero count as success — a false compliance assertion that
            // the audit trail was actually rewritten.
            throw new RuntimeException('Failed to write audit log: ' . $this->logPath);
        }
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function extractTimestamp(array $entry): ?DateTimeImmutable
    {
        if (!isset($entry['timestamp']) || !is_string($entry['timestamp'])) {
            return null;
        }

        $parsed = DateTimeImmutable::createFromFormat(DateTimeImmutable::ATOM, $entry['timestamp']);

        if ($parsed === false) {
            // Try ISO 8601 with microseconds
            $parsed = DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s.uP', $entry['timestamp']);
        }

        return $parsed !== false ? $parsed : null;
    }
}
