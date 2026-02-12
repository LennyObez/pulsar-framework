<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\ABTest;

use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Cms\ABTest\Experiment;
use Pulsar\Extension\Cms\ABTest\ExperimentStatus;
use Pulsar\Extension\Cms\ABTest\ExperimentVariant;
use Pulsar\Extension\Cms\Internal\ABTest\TrafficSplitter;

#[CoversClass(TrafficSplitter::class)]
final class TrafficSplitterTest extends TestCase
{
    private TrafficSplitter $splitter;

    protected function setUp(): void
    {
        $this->splitter = new TrafficSplitter();
    }

    #[Test]
    public function same_visitor_always_gets_same_variant(): void
    {
        $experiment = $this->createExperiment('exp-1');
        $variants = [
            $this->createVariant('v-a', 'exp-1', 'content-a', 50),
            $this->createVariant('v-b', 'exp-1', 'content-b', 50),
        ];

        $visitorId = 'visitor-42';

        $first = $this->splitter->selectVariant($experiment, $variants, $visitorId);
        $second = $this->splitter->selectVariant($experiment, $variants, $visitorId);
        $third = $this->splitter->selectVariant($experiment, $variants, $visitorId);

        self::assertSame($first->id, $second->id);
        self::assertSame($second->id, $third->id);
    }

    #[Test]
    public function different_visitors_can_get_different_variants(): void
    {
        $experiment = $this->createExperiment('exp-2');
        $variants = [
            $this->createVariant('v-a', 'exp-2', 'content-a', 50),
            $this->createVariant('v-b', 'exp-2', 'content-b', 50),
        ];

        $assignedA = 0;
        $assignedB = 0;

        // With 100 visitors, both variants should get some traffic
        for ($i = 0; $i < 100; $i++) {
            $variant = $this->splitter->selectVariant($experiment, $variants, "visitor-{$i}");

            if ($variant->id === 'v-a') {
                $assignedA++;
            } else {
                $assignedB++;
            }
        }

        self::assertGreaterThan(0, $assignedA, 'Variant A should receive some traffic');
        self::assertGreaterThan(0, $assignedB, 'Variant B should receive some traffic');
    }

    #[Test]
    public function weight_distribution_favors_higher_weight(): void
    {
        $experiment = $this->createExperiment('exp-3');
        $variants = [
            $this->createVariant('v-heavy', 'exp-3', 'content-heavy', 90),
            $this->createVariant('v-light', 'exp-3', 'content-light', 10),
        ];

        $heavyCount = 0;
        $lightCount = 0;

        for ($i = 0; $i < 1000; $i++) {
            $variant = $this->splitter->selectVariant($experiment, $variants, "visitor-weight-{$i}");

            if ($variant->id === 'v-heavy') {
                $heavyCount++;
            } else {
                $lightCount++;
            }
        }

        // With 90/10 split over 1000 visitors, heavy should clearly dominate
        self::assertGreaterThan($lightCount, $heavyCount, 'Higher weight variant should receive more traffic');
        self::assertGreaterThan(500, $heavyCount, 'Heavy variant should get majority of traffic');
    }

    #[Test]
    public function single_variant_always_selected(): void
    {
        $experiment = $this->createExperiment('exp-4');
        $variants = [
            $this->createVariant('v-only', 'exp-4', 'content-only', 100),
        ];

        $result = $this->splitter->selectVariant($experiment, $variants, 'any-visitor');

        self::assertSame('v-only', $result->id);
    }

    #[Test]
    public function empty_variants_throws_exception(): void
    {
        $experiment = $this->createExperiment('exp-5');

        $this->expectException(InvalidArgumentException::class);
        $this->splitter->selectVariant($experiment, [], 'visitor-1');
    }

    #[Test]
    public function deterministic_across_experiments(): void
    {
        $experimentA = $this->createExperiment('exp-a');
        $experimentB = $this->createExperiment('exp-b');

        $variants = [
            $this->createVariant('v-1', 'exp-a', 'content-1', 50),
            $this->createVariant('v-2', 'exp-a', 'content-2', 50),
        ];
        $variantsB = [
            $this->createVariant('v-1', 'exp-b', 'content-1', 50),
            $this->createVariant('v-2', 'exp-b', 'content-2', 50),
        ];

        $visitorId = 'visitor-cross';

        // Same visitor in different experiments may get different variants
        // (because experiment ID is part of the hash)
        $resultA = $this->splitter->selectVariant($experimentA, $variants, $visitorId);
        $resultB = $this->splitter->selectVariant($experimentB, $variantsB, $visitorId);

        // Both should be valid variant IDs
        self::assertContains($resultA->id, ['v-1', 'v-2']);
        self::assertContains($resultB->id, ['v-1', 'v-2']);
    }

    private function createExperiment(string $id): Experiment
    {
        return new Experiment(
            id: $id,
            name: "Test Experiment {$id}",
            contentId: 'content-original',
            status: ExperimentStatus::Running,
            trafficPercentage: 1.0,
            startAt: new DateTimeImmutable(),
            endAt: null,
            createdAt: new DateTimeImmutable(),
        );
    }

    private function createVariant(string $id, string $experimentId, string $contentId, int $weight): ExperimentVariant
    {
        return new ExperimentVariant(
            id: $id,
            experimentId: $experimentId,
            name: "Variant {$id}",
            contentId: $contentId,
            weight: $weight,
        );
    }
}
