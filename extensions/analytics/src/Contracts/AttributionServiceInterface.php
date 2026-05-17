<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Contracts;

use DateTimeImmutable;
use Pulsar\Api\Api;
use Pulsar\Extension\Analytics\Domain\AttributionModel;
use Pulsar\Extension\Analytics\Domain\AttributionResult;

/**
 * Attribution modeling for conversion credit assignment.
 * @api
 */
#[Api(since: '1.0.0')]
interface AttributionServiceInterface
{
    /**
     * Calculate attribution for conversions using the specified model.
     *
     * @return list<AttributionResult>
     */
    public function calculate(
        string $siteId,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
        AttributionModel $model,
        ?string $goalId = null,
    ): array;

    /**
     * Compare attribution across multiple models.
     *
     * @return array<string, list<AttributionResult>> Keyed by model name
     */
    public function compareModels(
        string $siteId,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
        ?string $goalId = null,
    ): array;
}
