<?php

declare(strict_types=1);

namespace Pulsar\Extension\AiGovernance\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
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

    public function testAssessInitializesEmptyFindings(): void
    {
        $this->store->assess('model-1');
        self::assertSame([], $this->store->getFindings('model-1'));
    }

    public function testAddAndRetrieveFindings(): void
    {
        $finding = $this->createFinding('f1', ImpactSeverity::High);
        $this->store->addFinding('model-1', $finding);

        $findings = $this->store->getFindings('model-1');
        self::assertCount(1, $findings);
        self::assertSame('f1', $findings[0]->id);
        self::assertSame(ImpactSeverity::High, $findings[0]->severity);
    }

    public function testGetFindingsReturnsEmptyForUnknownModel(): void
    {
        self::assertSame([], $this->store->getFindings('nonexistent'));
    }

    public function testRiskScoreZeroForNoFindings(): void
    {
        self::assertSame(0.0, $this->store->getRiskScore('model-1'));
    }

    public function testRiskScoreForSingleLowFinding(): void
    {
        $this->store->addFinding('m1', $this->createFinding('f1', ImpactSeverity::Low));

        // Weight: 1.0 / 1 finding = 1.0
        self::assertSame(1.0, $this->store->getRiskScore('m1'));
    }

    public function testRiskScoreForSingleCriticalFinding(): void
    {
        $this->store->addFinding('m1', $this->createFinding('f1', ImpactSeverity::Critical));

        // Weight: 10.0 / 1 finding = 10.0
        self::assertSame(10.0, $this->store->getRiskScore('m1'));
    }

    public function testRiskScoreForMixedSeverities(): void
    {
        $this->store->addFinding('m1', $this->createFinding('f1', ImpactSeverity::Low));     // 1.0
        $this->store->addFinding('m1', $this->createFinding('f2', ImpactSeverity::Medium));  // 3.0
        $this->store->addFinding('m1', $this->createFinding('f3', ImpactSeverity::High));    // 6.0

        // Average: (1.0 + 3.0 + 6.0) / 3 = 3.33
        self::assertSame(3.33, $this->store->getRiskScore('m1'));
    }

    public function testRiskScoreCappedAtTen(): void
    {
        // 3 critical findings: (10 + 10 + 10) / 3 = 10.0 exactly
        $this->store->addFinding('m1', $this->createFinding('f1', ImpactSeverity::Critical));
        $this->store->addFinding('m1', $this->createFinding('f2', ImpactSeverity::Critical));
        $this->store->addFinding('m1', $this->createFinding('f3', ImpactSeverity::Critical));

        self::assertSame(10.0, $this->store->getRiskScore('m1'));
    }

    public function testMultipleModelsIsolated(): void
    {
        $this->store->addFinding('m1', $this->createFinding('f1', ImpactSeverity::High));
        $this->store->addFinding('m2', $this->createFinding('f2', ImpactSeverity::Low));

        self::assertCount(1, $this->store->getFindings('m1'));
        self::assertCount(1, $this->store->getFindings('m2'));
        self::assertNotSame(
            $this->store->getRiskScore('m1'),
            $this->store->getRiskScore('m2'),
        );
    }

    /**
     * @param non-empty-string $id
     */
    private function createFinding(string $id, ImpactSeverity $severity): ImpactFinding
    {
        return new ImpactFinding(
            id: $id,
            category: ImpactCategory::Fairness,
            severity: $severity,
            title: 'Test Finding',
            description: 'A test finding for assessment',
            recommendation: 'Review and mitigate',
        );
    }
}
