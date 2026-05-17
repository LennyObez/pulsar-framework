<?php

declare(strict_types=1);

namespace Pulsar\Http\Validation\Analyzer\Policy;

use DateTimeImmutable;
use Override;
use Pulsar\Api\Api;
use Pulsar\Http\Validation\Analyzer\AnalyzerFinding;
use Pulsar\Http\Validation\Analyzer\FindingSeverity;
use Pulsar\Http\Validation\Analyzer\PolicyAnalyzerInterface;

use function is_string;
use function sprintf;

/**
 * Flags data with timestamps beyond a configured retention period.
 *
 * Advisory only: not a compliance gate. This analyzer checks whether
 * timestamp values are older than the configured retention period.
 * Default retention period is 365 days. Results should be reviewed by
 * qualified data governance personnel.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class RetentionPeriodAnalyzer implements PolicyAnalyzerInterface
{
    public function __construct(
        private int $retentionDays = 365,
    ) {}

    /**
     * @return list<AnalyzerFinding>
     */
    #[Override]
    public function analyze(string $field, mixed $value, array $data): array
    {
        if (!is_string($value) || $value === '') {
            return [];
        }

        $timestamp = DateTimeImmutable::createFromFormat('Y-m-d', $value);

        if ($timestamp === false) {
            $timestamp = DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s', $value);
        }

        if ($timestamp === false) {
            $timestamp = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value);
        }

        if ($timestamp === false) {
            return [];
        }

        $now = new DateTimeImmutable();

        if ($timestamp > $now) {
            return [];
        }

        $daysDiff = (int) $now->diff($timestamp)->days;

        if ($daysDiff > $this->retentionDays) {
            return [
                new AnalyzerFinding(
                    severity: FindingSeverity::Warning,
                    confidence: 0.8,
                    field: $field,
                    pattern: sprintf('Data is %d days old, exceeding retention period of %d days', $daysDiff, $this->retentionDays),
                    recommendation: sprintf(
                        'Data in field "%s" is %d days old, exceeding the configured retention period of %d days. Consider archiving or purging per data retention policy.',
                        $field,
                        $daysDiff,
                        $this->retentionDays,
                    ),
                ),
            ];
        }

        return [];
    }
}
