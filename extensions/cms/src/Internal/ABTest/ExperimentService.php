<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Internal\ABTest;

use DateTimeImmutable;
use Pulsar\Api\Internal;
use Pulsar\Extension\Cms\ABTest\ConversionEvent;
use Pulsar\Extension\Cms\ABTest\Experiment;
use Pulsar\Extension\Cms\ABTest\ExperimentRepositoryInterface;
use Pulsar\Extension\Cms\ABTest\ExperimentResult;
use Pulsar\Extension\Cms\ABTest\ExperimentStatus;
use Pulsar\Extension\Cms\ABTest\ExperimentVariant;
use Pulsar\Extension\Cms\Exception\CmsException;
use Pulsar\Extension\Cms\Support\UuidGenerator;

use function array_map;
use function count;
use function max;
use function sqrt;

/**
 * Orchestrates A/B test experiment lifecycle: creation, variant management,
 * traffic splitting, conversion recording, and statistical results.
 */
#[Internal(reason: 'A/B test implementation detail')]
final readonly class ExperimentService
{
    public function __construct(
        private ExperimentRepositoryInterface $repository,
        private TrafficSplitter $splitter,
    ) {}

    public function createExperiment(string $name, string $contentId, float $trafficPercentage): Experiment
    {
        $experiment = new Experiment(
            id: UuidGenerator::v7(),
            name: $name,
            contentId: $contentId,
            status: ExperimentStatus::Draft,
            trafficPercentage: max(0.0, min(1.0, $trafficPercentage)),
            startAt: null,
            endAt: null,
            createdAt: new DateTimeImmutable(),
        );

        $this->repository->save($experiment);

        return $experiment;
    }

    public function addVariant(string $experimentId, string $name, string $contentId, int $weight): ExperimentVariant
    {
        $experiment = $this->repository->findById($experimentId);

        if ($experiment === null) {
            throw CmsException::contentNotFound($experimentId);
        }

        if ($experiment->status !== ExperimentStatus::Draft) {
            throw new CmsException('Cannot add variants to a non-draft experiment');
        }

        $variant = new ExperimentVariant(
            id: UuidGenerator::v7(),
            experimentId: $experimentId,
            name: $name,
            contentId: $contentId,
            weight: max(1, $weight),
        );

        $this->repository->saveVariant($variant);

        return $variant;
    }

    public function startExperiment(string $experimentId): Experiment
    {
        $experiment = $this->repository->findById($experimentId);

        if ($experiment === null) {
            throw CmsException::contentNotFound($experimentId);
        }

        if ($experiment->status !== ExperimentStatus::Draft) {
            throw new CmsException('Only draft experiments can be started');
        }

        $variants = $this->repository->findVariants($experimentId);

        if (count($variants) < 2) {
            throw new CmsException('Experiment requires at least 2 variants to start');
        }

        $started = $experiment->start();
        $this->repository->save($started);

        return $started;
    }

    public function stopExperiment(string $experimentId): Experiment
    {
        $experiment = $this->repository->findById($experimentId);

        if ($experiment === null) {
            throw CmsException::contentNotFound($experimentId);
        }

        if ($experiment->status !== ExperimentStatus::Running) {
            throw new CmsException('Only running experiments can be stopped');
        }

        $stopped = $experiment->stop();
        $this->repository->save($stopped);

        return $stopped;
    }

    public function getExperiment(string $id): ?Experiment
    {
        return $this->repository->findById($id);
    }

    /**
     * @return list<ExperimentVariant>
     */
    public function getVariants(string $experimentId): array
    {
        return $this->repository->findVariants($experimentId);
    }

    /**
     * @return list<Experiment>
     */
    public function getRunningExperiments(): array
    {
        return $this->repository->findRunning();
    }

    public function getActiveExperiment(string $contentId): ?Experiment
    {
        return $this->repository->findByContentId($contentId);
    }

    public function selectVariantForVisitor(string $contentId, string $visitorId): ?ExperimentVariant
    {
        $experiment = $this->repository->findByContentId($contentId);

        if ($experiment === null) {
            return null;
        }

        $variants = $this->repository->findVariants($experiment->id);

        if ($variants === []) {
            return null;
        }

        return $this->splitter->selectVariant($experiment, $variants, $visitorId);
    }

    public function recordConversion(
        string $experimentId,
        string $variantId,
        string $visitorId,
        string $type,
    ): void {
        $event = new ConversionEvent(
            id: UuidGenerator::v7(),
            experimentId: $experimentId,
            variantId: $variantId,
            visitorId: $visitorId,
            type: $type,
            createdAt: new DateTimeImmutable(),
        );

        $this->repository->recordConversion($event);
    }

    /**
     * Calculate statistical results for an experiment.
     *
     * @return list<ExperimentResult>
     */
    public function getResults(string $experimentId): array
    {
        $variants = $this->repository->findVariants($experimentId);
        $counts = $this->repository->getConversionCounts($experimentId);

        return array_map(function (ExperimentVariant $variant) use ($counts): ExperimentResult {
            $data = $counts[$variant->id] ?? ['impressions' => 0, 'conversions' => 0];
            $impressions = $data['impressions'];
            $conversions = $data['conversions'];
            $rate = $impressions > 0 ? $conversions / $impressions : 0.0;
            $confidence = $this->calculateConfidence($impressions, $conversions);

            return new ExperimentResult(
                variantId: $variant->id,
                variantName: $variant->name,
                impressions: $impressions,
                conversions: $conversions,
                conversionRate: $rate,
                confidenceLevel: $confidence,
            );
        }, $variants);
    }

    /**
     * Approximate confidence using the Wilson score interval lower bound.
     *
     * Returns a value between 0.0 and 1.0 indicating how confident we are
     * that the true conversion rate is at least as high as observed.
     */
    private function calculateConfidence(int $impressions, int $conversions): float
    {
        if ($impressions === 0) {
            return 0.0;
        }

        $n = (float) $impressions;
        $p = (float) $conversions / $n;

        // z = 1.96 for 95% confidence
        $z = 1.96;
        $z2 = $z * $z;

        $denominator = 1.0 + $z2 / $n;
        $centre = $p + $z2 / (2.0 * $n);
        $spread = $z * sqrt(($p * (1.0 - $p) + $z2 / (4.0 * $n)) / $n);

        $lowerBound = ($centre - $spread) / $denominator;

        return max(0.0, min(1.0, $lowerBound));
    }
}
