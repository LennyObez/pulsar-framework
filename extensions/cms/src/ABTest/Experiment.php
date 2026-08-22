<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\ABTest;

use DateTimeImmutable;
use Pulsar\Api\Api;

/**
 * @psalm-api Public DTO referenced through ExperimentRepositoryInterface and
 *            consumed by user-land code; class-level marker for findUnusedCode.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class Experiment
{
    public function __construct(
        public string $id,
        public string $name,
        public string $contentId,
        public ExperimentStatus $status,
        public float $trafficPercentage,
        public ?DateTimeImmutable $startAt,
        public ?DateTimeImmutable $endAt,
        public DateTimeImmutable $createdAt,
    ) {}

    public function start(): self
    {
        return clone($this, [
            'status' => ExperimentStatus::Running,
            'startAt' => new DateTimeImmutable(),
        ]);
    }

    public function stop(): self
    {
        return clone($this, [
            'status' => ExperimentStatus::Completed,
            'endAt' => new DateTimeImmutable(),
        ]);
    }

    public function cancel(): self
    {
        return clone($this, [
            'status' => ExperimentStatus::Cancelled,
            'endAt' => new DateTimeImmutable(),
        ]);
    }

    public function isRunning(): bool
    {
        return $this->status === ExperimentStatus::Running;
    }
}
