<?php

declare(strict_types=1);

namespace Pulsar\Saga\Step;

use DateTimeImmutable;
use Pulsar\Api\Api;

/**
 * Readonly DTO representing a saga step execution result.
 *
 * Maps to the `saga_step_results` table schema. Forward execution rows
 * are append-only. Compensation creates new rows with direction=compensating.
 * Irreversible steps receive status=skipped during compensation.
 */
#[Api(since: '1.0.0')]
final readonly class SagaStepResult
{
    /**
     * @param array<string, mixed>|null $resultData
     */
    public function __construct(
        public string $id,
        public string $instanceId,
        public string $stepName,
        public int $stepIndex,
        public SagaStepDirection $direction,
        public SagaStepStatus $status,
        public ?string $idempotencyKey,
        public int $attempts,
        public ?array $resultData,
        public ?string $errorMessage,
        public DateTimeImmutable $startedAt,
        public ?DateTimeImmutable $completedAt,
    ) {}
}
