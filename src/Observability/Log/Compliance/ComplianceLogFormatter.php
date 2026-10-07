<?php

declare(strict_types=1);

namespace Pulsar\Observability\Log\Compliance;

use Pulsar\Api\Api;
use Pulsar\Observability\Log\LogEntry;

/**
 * Contract for regulation-specific log entry transformers.
 *
 * Implementations apply masking, pseudonymization, or metadata enrichment
 * to log entries before they are written to sinks. Each formatter returns
 * a new LogEntry since LogEntry is readonly.
 * @api
 */
#[Api(since: '1.0.0')]
interface ComplianceLogFormatter
{
    /**
     * Transform a log entry for compliance purposes.
     */
    public function format(LogEntry $entry): LogEntry;
}
