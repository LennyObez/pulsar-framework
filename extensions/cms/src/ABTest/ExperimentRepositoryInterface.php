<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\ABTest;

use Pulsar\Api\Api;

/**
 * Repository for A/B test experiments, variants, and conversion events.
 *
 * @psalm-api Public binding contract; implemented by DbExperimentRepository and
 *            consumed by ExperimentService and user-land code.
 * @api
 */
#[Api(since: '1.0.0')]
interface ExperimentRepositoryInterface
{
    public function findById(string $id): ?Experiment;

    /**
     * Find the active (running) experiment for a content item.
     */
    public function findByContentId(string $contentId): ?Experiment;

    /**
     * @return list<Experiment>
     */
    public function findRunning(): array;

    public function save(Experiment $experiment): void;

    public function saveVariant(ExperimentVariant $variant): void;

    /**
     * @return list<ExperimentVariant>
     */
    public function findVariants(string $experimentId): array;

    public function recordConversion(ConversionEvent $event): void;

    /**
     * @return array<string, array{impressions: int, conversions: int}>
     */
    public function getConversionCounts(string $experimentId): array;
}
