<?php

declare(strict_types=1);

namespace Pulsar\Extension\Accessibility\Audit;

use Pulsar\Api\Api;

/**
 * Summary statistics for an accessibility audit.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class AuditSummary
{
    public int $totalViolations;

    public function __construct(
        public int $totalChecks,
        public int $errorCount,
        public int $warningCount,
        public int $infoCount,
        public int $filesAudited,
    ) {
        $this->totalViolations = $this->errorCount + $this->warningCount + $this->infoCount;
    }

    /**
     * @return array<string, int>
     */
    public function toArray(): array
    {
        return [
            'total_checks' => $this->totalChecks,
            'errors' => $this->errorCount,
            'warnings' => $this->warningCount,
            'info' => $this->infoCount,
            'total_violations' => $this->totalViolations,
            'files_audited' => $this->filesAudited,
        ];
    }
}
