<?php

declare(strict_types=1);

namespace Pulsar\Observability\Log\Compliance;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Observability\Log\LogEntry;

use function array_key_exists;
use function in_array;

/**
 * Adds SOX data classification metadata and structures change snapshots.
 *
 * Receives already-masked snapshots (masking at SnapshotCapture, not here)
 * and adds classification metadata. Structures before/after snapshots in
 * a standard format for audit trail purposes.
 *
 * Supports controls for SOX Section 302/404 internal controls over
 * financial reporting.
 */
#[Internal(reason: 'Compliance formatter implementation detail')]
final class SoxLogFormatter implements ComplianceLogFormatter
{
    #[Override]
    public function format(LogEntry $entry): LogEntry
    {
        $context = $entry->context;

        $context['data_classification'] = $this->classifyData($context);

        if (array_key_exists('before', $context) || array_key_exists('after', $context)) {
            $context['change_snapshot'] = [
                'before' => $context['before'] ?? null,
                'after' => $context['after'] ?? null,
            ];

            unset($context['before'], $context['after']);
        }

        $context['sox_controlled'] = true;

        return new LogEntry(
            level: $entry->level,
            message: $entry->message,
            context: $context,
            channel: $entry->channel,
            timestamp: $entry->timestamp,
        );
    }

    /**
     * Classify data sensitivity based on context keys.
     *
     * @param array<string, mixed> $context
     */
    private function classifyData(array $context): string
    {
        $financialKeys = ['amount', 'balance', 'revenue', 'expense', 'ledger', 'account', 'transaction'];

        if (array_any($context, static fn(mixed $value, string $key): bool => in_array(strtolower($key), $financialKeys, true))) {
            return 'financial';
        }

        if (array_key_exists('before', $context) || array_key_exists('after', $context)) {
            return 'change_record';
        }

        return 'operational';
    }
}
