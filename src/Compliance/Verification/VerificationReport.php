<?php

declare(strict_types=1);

namespace Pulsar\Compliance\Verification;

use NoDiscard;
use Pulsar\Api\Api;
use Pulsar\Compliance\ComplianceFramework;

use function array_filter;
use function array_values;
use function count;
use function round;

/**
 * Complete verification report produced by the engine.
 *
 * Contains per-check results grouped by domain, plus aggregate statistics.
 */
#[Api(since: '1.0.0')]
final readonly class VerificationReport
{
    /**
     * @param list<ComplianceFramework> $frameworks  Frameworks that were verified
     * @param list<CheckResult>         $results     Individual check results
     * @param list<ConflictReport>      $conflicts   Detected cross-framework conflicts
     * @param list<RegressionViolation> $regressions Detected configuration regressions
     */
    public function __construct(
        public array $frameworks,
        public array $results,
        public array $conflicts = [],
        public array $regressions = [],
        public ?int $generatedAt = null,
    ) {}

    /**
     * All results with a given status.
     *
     * @return list<CheckResult>
     */
    #[NoDiscard]
    public function byStatus(CheckStatus $status): array
    {
        return array_values(array_filter(
            $this->results,
            static fn(CheckResult $r): bool => $r->status === $status,
        ));
    }

    /**
     * All results in a given domain.
     *
     * @return list<CheckResult>
     */
    #[NoDiscard]
    public function byDomain(ComplianceCheckDomain $domain): array
    {
        return array_values(array_filter(
            $this->results,
            static fn(CheckResult $r): bool => $r->domain === $domain,
        ));
    }

    #[NoDiscard]
    public function passCount(): int
    {
        return count($this->byStatus(CheckStatus::Pass));
    }

    #[NoDiscard]
    public function failCount(): int
    {
        return count($this->byStatus(CheckStatus::Fail));
    }

    #[NoDiscard]
    public function skipCount(): int
    {
        return count($this->byStatus(CheckStatus::Skip));
    }

    #[NoDiscard]
    public function totalCount(): int
    {
        return count($this->results);
    }

    #[NoDiscard]
    public function passRate(): float
    {
        $evaluated = $this->passCount() + $this->failCount();

        if ($evaluated === 0) {
            return 0.0;
        }

        return round((float) $this->passCount() / (float) $evaluated * 100.0, 2);
    }

    #[NoDiscard]
    public function hasFailures(): bool
    {
        return $this->failCount() > 0;
    }

    #[NoDiscard]
    public function hasConflicts(): bool
    {
        return $this->conflicts !== [];
    }

    #[NoDiscard]
    public function hasRegressions(): bool
    {
        return $this->regressions !== [];
    }

    /**
     * Produce a structured array for JSON serialization.
     *
     * @return array<string, mixed>
     */
    #[NoDiscard]
    public function toArray(): array
    {
        return [
            'generated_at' => $this->generatedAt,
            'frameworks' => array_map(
                static fn(ComplianceFramework $f): string => $f->value,
                $this->frameworks,
            ),
            'summary' => [
                'total' => $this->totalCount(),
                'pass' => $this->passCount(),
                'fail' => $this->failCount(),
                'skip' => $this->skipCount(),
                'pass_rate' => $this->passRate(),
            ],
            'results' => array_map(
                static fn(CheckResult $r): array => [
                    'check_id' => $r->checkId,
                    'domain' => $r->domain->value,
                    'status' => $r->status->value,
                    'message' => $r->message,
                    'evidence' => $r->evidence,
                    'remediations' => $r->remediations,
                    'verified_at' => $r->verifiedAt,
                ],
                $this->results,
            ),
            'conflicts' => array_map(
                static fn(ConflictReport $c): array => $c->toArray(),
                $this->conflicts,
            ),
            'regressions' => array_map(
                static fn(RegressionViolation $v): array => $v->toArray(),
                $this->regressions,
            ),
            'disclaimer' => 'This report documents automated verification of control coverage. '
                . 'It does not constitute compliance certification.',
        ];
    }
}
