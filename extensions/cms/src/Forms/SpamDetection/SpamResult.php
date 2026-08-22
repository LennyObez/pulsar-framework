<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Forms\SpamDetection;

use Pulsar\Api\Api;

/**
 * Result of spam detection analysis.
 *
 * @psalm-api Public DTO returned from SpamDetectorInterface implementations;
 *            consumed by SpamScorer aggregation.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class SpamResult
{
    public function __construct(
        public bool $isSpam,
        public float $score,
        public ?string $reason,
    ) {}
}
