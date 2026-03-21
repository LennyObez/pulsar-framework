<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Forms\SpamDetection;

use Pulsar\Api\Api;

/**
 * Aggregates multiple spam detectors and produces a combined score.
 *
 * @psalm-api Resolved from the DI container by FormSubmissionService;
 *            extensions register additional detectors during boot.
 * @api
 */
#[Api(since: '1.0.0')]
final class SpamScorer
{
    /** @var list<SpamDetectorInterface> */
    private array $detectors = [];

    public function __construct(
        private readonly float $threshold = 5.0,
    ) {}

    public function addDetector(SpamDetectorInterface $detector): void
    {
        $this->detectors[] = $detector;
    }

    /**
     * Run all detectors and return the combined result.
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $meta
     */
    public function score(array $data, array $meta): SpamResult
    {
        $totalScore = 0.0;
        $reasons = [];

        foreach ($this->detectors as $detector) {
            $result = $detector->detect($data, $meta);

            $totalScore += $result->score;

            if ($result->reason !== null) {
                $reasons[] = $result->reason;
            }
        }

        $isSpam = $totalScore >= $this->threshold;
        $reason = $reasons !== [] ? implode('; ', $reasons) : null;

        return new SpamResult($isSpam, $totalScore, $reason);
    }
}
