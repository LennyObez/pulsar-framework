<?php

declare(strict_types=1);

namespace Pulsar\Security\PostureScore;

use DateTimeImmutable;
use NoDiscard;
use Pulsar\Api\Api;

/**
 * Result of a security posture evaluation.
 */
#[Api(since: '1.0.0')]
final readonly class PostureResult
{
    /**
     * @param int                                   $score           0-100 posture score
     * @param array<string, bool>                   $controls        control name => active
     * @param array<string, string>                 $recommendations control name => fix text (missing controls only)
     */
    public function __construct(
        public int $score,
        public array $controls,
        public array $recommendations,
        public DateTimeImmutable $evaluatedAt,
    ) {}

    #[NoDiscard]
    public function grade(): string
    {
        return match (true) {
            $this->score >= 90 => 'A',
            $this->score >= 80 => 'B',
            $this->score >= 70 => 'C',
            $this->score >= 60 => 'D',
            default => 'F',
        };
    }

    /**
     * @return array<string, mixed>
     */
    #[NoDiscard]
    public function toArray(): array
    {
        return [
            'score' => $this->score,
            'grade' => $this->grade(),
            'controls' => $this->controls,
            'recommendations' => $this->recommendations,
            'evaluated_at' => $this->evaluatedAt->format('c'),
        ];
    }
}
