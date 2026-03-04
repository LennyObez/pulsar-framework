<?php

declare(strict_types=1);

namespace Pulsar\Extension\AiGovernance\Tests\Unit\Store;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
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

    #[Test]
    public function explainReturnsNullForUnknownDecision(): void
    {
        self::assertNull($this->store->explain('unknown'));
    }

    #[Test]
    public function recordAndExplain(): void
    {
        $explanation = $this->createExplanation('dec-1', 'model-1');
        $this->store->record($explanation);

        $found = $this->store->explain('dec-1');
        self::assertNotNull($found);
        self::assertSame('dec-1', $found->decisionId);
        self::assertSame('model-1', $found->modelId);
    }

    #[Test]
    public function recordOverwritesSameDecisionId(): void
    {
        $this->store->record($this->createExplanation('dec-1', 'model-1', 'First reason'));
        $this->store->record($this->createExplanation('dec-1', 'model-2', 'Updated reason'));

        $found = $this->store->explain('dec-1');
        self::assertSame('Updated reason', $found->summary);
        self::assertSame('model-2', $found->modelId);
    }

    #[Test]
    public function getByModelReturnsOnlyMatchingModel(): void
    {
        $this->store->record($this->createExplanation('dec-1', 'model-A'));
        $this->store->record($this->createExplanation('dec-2', 'model-B'));
        $this->store->record($this->createExplanation('dec-3', 'model-A'));

        $results = $this->store->getByModel('model-A');

        self::assertCount(2, $results);
        self::assertSame('model-A', $results[0]->modelId);
        self::assertSame('model-A', $results[1]->modelId);
    }

    #[Test]
    public function getByModelReturnsEmptyWhenNoMatch(): void
    {
        $this->store->record($this->createExplanation('dec-1', 'model-A'));

        self::assertSame([], $this->store->getByModel('model-nonexistent'));
    }

    #[Test]
    public function getByModelReturnsEmptyWhenStoreIsEmpty(): void
    {
        self::assertSame([], $this->store->getByModel('any'));
    }

    private function createExplanation(
        string $decisionId,
        string $modelId,
        string $summary = 'Test explanation',
    ): Explanation {
        return new Explanation(
            decisionId: $decisionId,
            modelId: $modelId,
            summary: $summary,
            factors: [new DecisionFactor('input_length', 0.8, 'Primary factor')],
            confidence: 0.95,
            generatedAt: new DateTimeImmutable('2026-03-01'),
        );
    }
}
