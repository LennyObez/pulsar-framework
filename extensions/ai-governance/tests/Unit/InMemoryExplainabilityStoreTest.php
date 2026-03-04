<?php

declare(strict_types=1);

namespace Pulsar\Extension\AiGovernance\Tests\Unit;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\AiGovernance\Dto\DecisionFactor;
use Pulsar\Extension\AiGovernance\Dto\Explanation;
use Pulsar\Extension\AiGovernance\Internal\Store\InMemoryExplainabilityStore;

#[CoversClass(InMemoryExplainabilityStore::class)]
final class InMemoryExplainabilityStoreTest extends TestCase
{
    private InMemoryExplainabilityStore $store;

    protected function setUp(): void
    {
        $this->store = new InMemoryExplainabilityStore();
    }

    public function testRecordAndExplain(): void
    {
        $explanation = $this->createExplanation('dec-1', 'model-1');
        $this->store->record($explanation);

        $retrieved = $this->store->explain('dec-1');
        self::assertNotNull($retrieved);
        self::assertSame('dec-1', $retrieved->decisionId);
        self::assertSame('model-1', $retrieved->modelId);
        self::assertSame(0.95, $retrieved->confidence);
    }

    public function testExplainReturnsNullForUnknown(): void
    {
        self::assertNull($this->store->explain('nonexistent'));
    }

    public function testGetByModelFiltersCorrectly(): void
    {
        $this->store->record($this->createExplanation('d1', 'model-1'));
        $this->store->record($this->createExplanation('d2', 'model-2'));
        $this->store->record($this->createExplanation('d3', 'model-1'));

        $model1Explanations = $this->store->getByModel('model-1');
        self::assertCount(2, $model1Explanations);
        self::assertSame('d1', $model1Explanations[0]->decisionId);
        self::assertSame('d3', $model1Explanations[1]->decisionId);
    }

    public function testGetByModelReturnsEmptyForUnknown(): void
    {
        self::assertSame([], $this->store->getByModel('nonexistent'));
    }

    public function testRecordOverwritesSameDecisionId(): void
    {
        $this->store->record($this->createExplanation('d1', 'model-1', confidence: 0.5));
        $this->store->record($this->createExplanation('d1', 'model-1', confidence: 0.9));

        $explanation = $this->store->explain('d1');
        self::assertNotNull($explanation);
        self::assertSame(0.9, $explanation->confidence);
    }

    public function testExplanationContainsFactors(): void
    {
        $explanation = $this->createExplanation('d1', 'model-1');
        $this->store->record($explanation);

        $retrieved = $this->store->explain('d1');
        self::assertNotNull($retrieved);
        self::assertCount(1, $retrieved->factors);
        self::assertSame('input_quality', $retrieved->factors[0]->name);
        self::assertSame(0.8, $retrieved->factors[0]->weight);
    }

    /**
     * @param non-empty-string $decisionId
     * @param non-empty-string $modelId
     */
    private function createExplanation(
        string $decisionId,
        string $modelId,
        float $confidence = 0.95,
    ): Explanation {
        return new Explanation(
            decisionId: $decisionId,
            modelId: $modelId,
            summary: 'The model chose option A based on input quality',
            factors: [
                new DecisionFactor(
                    name: 'input_quality',
                    weight: 0.8,
                    description: 'High-quality structured input increased confidence',
                ),
            ],
            confidence: $confidence,
            generatedAt: new DateTimeImmutable('2026-01-01'),
            alternativesConsidered: ['option_b', 'option_c'],
        );
    }
}
