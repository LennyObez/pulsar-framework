<?php

declare(strict_types=1);

namespace Pulsar\Security\AntiSpam;

use NoDiscard;
use Pulsar\Api\Api;

use function array_filter;
use function array_map;
use function array_sum;
use function array_values;
use function min;

/**
 * Aggregate result from the full anti-spam pipeline.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class AntiSpamResult
{
    /**
     * @param bool $passed Whether all checks passed (true = not spam)
     * @param list<AntiSpamCheckResult> $checkResults Individual check results
     * @param int $score Aggregate spam score (0–100, clamped)
     */
    public function __construct(
        public bool $passed,
        public array $checkResults,
        public int $score,
    ) {}

    /**
     * @param list<AntiSpamCheckResult> $checkResults
     */
    #[NoDiscard]
    public static function fromCheckResults(array $checkResults): self
    {
        $allPassed = true;

        foreach ($checkResults as $result) {
            if (!$result->passed) {
                $allPassed = false;

                break;
            }
        }

        $totalScore = (int) array_sum(array_map(
            static fn(AntiSpamCheckResult $r): int => $r->score,
            $checkResults,
        ));

        return new self(
            passed: $allPassed,
            checkResults: $checkResults,
            score: min(100, $totalScore),
        );
    }

    /**
     * @return list<string> Names of checks that failed
     */
    #[NoDiscard]
    public function failedChecks(): array
    {
        return array_values(array_map(
            static fn(AntiSpamCheckResult $r): string => $r->checkName,
            array_filter(
                $this->checkResults,
                static fn(AntiSpamCheckResult $r): bool => !$r->passed,
            ),
        ));
    }
}
