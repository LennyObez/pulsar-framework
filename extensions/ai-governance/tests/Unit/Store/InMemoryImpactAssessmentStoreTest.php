<?php

declare(strict_types=1);

namespace Pulsar\Extension\AiGovernance\Tests\Unit\Store;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\AiGovernance\Dto\ImpactFinding;
use Pulsar\Extension\AiGovernance\Enum\ImpactCategory;
use Pulsar\Extension\AiGovernance\Enum\ImpactSeverity;
use Pulsar\Extension\AiGovernance\Internal\Store\InMemoryImpactAssessmentStore;

#[CoversClass(InMemoryImpactAssessmentStore::class)]
final class InMemoryImpactAssessmentStoreTest extends TestCase
{
    private InMemoryImpactAssessmentStore $store;

    protected function setUp(): void
    {
        $this->store = new InMemoryImpactAssessmentStore();
    }

    #[Test]
    public function getFindingsReturnsEmptyForUnknownModel(): void
    {
        self::assertSame([], $this->store->getFindings('unknown'));
    }

    #[Test]
    public function assessInitializesEmptyFindings(): void
    {
        $this->store->assess('model-1', [ImpactCategory::Fairness]);

        self::assertSame([], $this->store->getFindings('model-1'));
    }

    #[Test]
    public function addFindingAndRetrieve(): void
    {
        $finding = $this->createFinding('F-001', ImpactSeverity::High);
        $this->store->addFinding('model-1', $finding);

        $findings = $this->store->getFindings('model-1');
        self::assertCount(1, $findings);
        self::assertSame('F-001', $findings[0]->id);
    }

    #[Test]
    public function multipleFindings(): void
    {
        $this->store->addFinding('model-1', $this->createFinding('F-001', ImpactSeverity::Low));
        $this->store->addFinding('model-1', $this->createFinding('F-002', ImpactSeverity::Critical));

        self::assertCount(2, $this->store->getFindings('model-1'));
    }

    #[Test]
    public function findingsAreScopedToModel(): void
    {
        $this->store->addFinding('model-1', $this->createFinding('F-001', ImpactSeverity::Low));
        $this->store->addFinding('model-2', $this->createFinding('F-002', ImpactSeverity::High));

        self::assertCount(1, $this->store->getFindings('model-1'));
        self::assertCount(1, $this->store->getFindings('model-2'));
    }

    #[Test]
    public function getRiskScoreReturnsZeroWithNoFindings(): void
    {
        self::assertSame(0.0, $this->store->getRiskScore('model-1'));
    }

    #[Test]
    public function getRiskScoreSingleLowFinding(): void
    {
        $this->store->addFinding('model-1', $this->createFinding('F-001', ImpactSeverity::Low));

        // Low weight = 1.0, count = 1, score = 1.0/1 = 1.0
        self::assertSame(1.0, $this->store->getRiskScore('model-1'));
    }

    #[Test]
    public function getRiskScoreSingleCriticalFinding(): void
    {
        $this->store->addFinding('model-1', $this->createFinding('F-001', ImpactSeverity::Critical));

        // Critical weight = 10.0, count = 1, score = 10.0/1 = 10.0
        self::assertSame(10.0, $this->store->getRiskScore('model-1'));
    }

    #[Test]
    public function getRiskScoreMixedSeverities(): void
    {
        $this->store->addFinding('model-1', $this->createFinding('F-001', ImpactSeverity::Low));       // 1.0
        $this->store->addFinding('model-1', $this->createFinding('F-002', ImpactSeverity::Medium));    // 3.0
        $this->store->addFinding('model-1', $this->createFinding('F-003', ImpactSeverity::High));      // 6.0
        $this->store->addFinding('model-1', $this->createFinding('F-004', ImpactSeverity::Critical));  // 10.0

        // Total = 20.0, count = 4, average = 5.0
        self::assertSame(5.0, $this->store->getRiskScore('model-1'));
    }

    #[Test]
    public function getRiskScoreCapsAtTen(): void
    {
        // Multiple critical findings: each = 10.0, average = 10.0, capped at 10.0
        $this->store->addFinding('model-1', $this->createFinding('F-001', ImpactSeverity::Critical));
        $this->store->addFinding('model-1', $this->createFinding('F-002', ImpactSeverity::Critical));

        self::assertSame(10.0, $this->store->getRiskScore('model-1'));
    }

    #[Test]
    public function addFindingWithoutPriorAssessInitializesAutomatically(): void
    {
        // addFinding should work without calling assess() first
        $this->store->addFinding('model-1', $this->createFinding('F-001', ImpactSeverity::Medium));
        self::assertCount(1, $this->store->getFindings('model-1'));
    }

    private function createFinding(string $id, ImpactSeverity $severity): ImpactFinding
    {
        return new ImpactFinding(
            id: $id,
            category: ImpactCategory::Fairness,
            severity: $severity,
            title: "Finding $id",
            description: "Description for $id",
            recommendation: "Fix $id",
        );
    }
}
