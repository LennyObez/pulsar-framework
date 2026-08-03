<?php

declare(strict_types=1);

namespace Pulsar\Extension\Analytics\Tests\Unit\Internal\Service;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Database\ConnectionInterface;
use Pulsar\Database\Result;
use Pulsar\Database\Row;
use Pulsar\Extension\Analytics\Domain\FunnelStep;
use Pulsar\Extension\Analytics\Domain\FunnelStepType;
use Pulsar\Extension\Analytics\Exception\AnalyticsException;
use Pulsar\Extension\Analytics\Internal\Service\FunnelService;

use function json_encode;

use const JSON_THROW_ON_ERROR;

final class FunnelServiceTest extends TestCase
{
    private FunnelService $service;
    private ConnectionInterface&Stub $connection;

    protected function setUp(): void
    {
        $this->connection = $this->createStub(ConnectionInterface::class);
        $this->service = new FunnelService($this->connection);
    }

    #[Test]
    public function createInsertsFunnelAndReturnsDefinition(): void
    {
        $this->connection->method('execute')->willReturn(1);

        $steps = [
            new FunnelStep(1, 'Landing', FunnelStepType::PageVisit, '/'),
            new FunnelStep(2, 'Signup', FunnelStepType::CustomEvent, 'signup'),
        ];

        $result = $this->service->create('site-001', 'Onboarding', $steps);

        self::assertSame('site-001', $result->siteId);
        self::assertSame('Onboarding', $result->name);
        self::assertCount(2, $result->steps);
        self::assertNotEmpty($result->id);
        self::assertSame(FunnelStepType::PageVisit, $result->steps[0]->type);
        self::assertSame(FunnelStepType::CustomEvent, $result->steps[1]->type);
    }

    #[Test]
    public function findByIdReturnsNullWhenNotFound(): void
    {
        $queryResult = new Result([]);
        $this->connection->method('query')->willReturn($queryResult);

        $result = $this->service->findById('nonexistent');

        self::assertNull($result);
    }

    #[Test]
    public function findByIdReturnsFunnelDefinition(): void
    {
        $stepsJson = json_encode([
            ['position' => 1, 'name' => 'Home', 'type' => 'page_visit', 'value' => '/'],
        ], JSON_THROW_ON_ERROR);

        $row = new Row([
            'id' => 'f-001',
            'site_id' => 'site-001',
            'name' => 'Test Funnel',
            'steps' => $stepsJson,
            'created_at' => '2025-06-01 12:00:00',
        ]);

        $queryResult = new Result([$row]);
        $this->connection->method('query')->willReturn($queryResult);

        $result = $this->service->findById('f-001');

        self::assertNotNull($result);
        self::assertSame('f-001', $result->id);
        self::assertSame('Test Funnel', $result->name);
        self::assertCount(1, $result->steps);
        self::assertSame(FunnelStepType::PageVisit, $result->steps[0]->type);
    }

    #[Test]
    public function listForSiteReturnsEmptyWhenNoFunnels(): void
    {
        $queryResult = new Result([]);
        $this->connection->method('query')->willReturn($queryResult);

        $result = $this->service->listForSite('site-001');

        self::assertSame([], $result);
    }

    #[Test]
    public function listForSiteReturnsMultipleFunnels(): void
    {
        $stepsJson1 = json_encode([
            ['position' => 1, 'name' => 'Home', 'type' => 'page_visit', 'value' => '/'],
        ], JSON_THROW_ON_ERROR);

        $stepsJson2 = json_encode([
            ['position' => 1, 'name' => 'Cart', 'type' => 'page_visit', 'value' => '/cart'],
            ['position' => 2, 'name' => 'Checkout', 'type' => 'custom_event', 'value' => 'purchase'],
        ], JSON_THROW_ON_ERROR);

        $rows = [
            new Row(['id' => 'f-001', 'site_id' => 'site-001', 'name' => 'Funnel A', 'steps' => $stepsJson1, 'created_at' => '2025-06-01 12:00:00']),
            new Row(['id' => 'f-002', 'site_id' => 'site-001', 'name' => 'Funnel B', 'steps' => $stepsJson2, 'created_at' => '2025-06-02 12:00:00']),
        ];
        $queryResult = new Result($rows);
        $this->connection->method('query')->willReturn($queryResult);

        $result = $this->service->listForSite('site-001');

        self::assertCount(2, $result);
        self::assertSame('Funnel A', $result[0]->name);
        self::assertSame('Funnel B', $result[1]->name);
    }

    #[Test]
    public function deleteThrowsWhenFunnelNotFound(): void
    {
        $this->connection->method('execute')->willReturn(0);

        $this->expectException(AnalyticsException::class);
        $this->expectExceptionMessageIsOrContains('not found');

        $this->service->delete('nonexistent');
    }

    #[Test]
    public function deleteSucceedsWhenFunnelExists(): void
    {
        $this->connection->method('execute')->willReturn(1);

        $this->service->delete('f-001');

        // No exception means success
        $this->addToAssertionCount(1);
    }

    #[Test]
    public function evaluateThrowsWhenFunnelNotFound(): void
    {
        $queryResult = new Result([]);
        $this->connection->method('query')->willReturn($queryResult);

        $this->expectException(AnalyticsException::class);

        $this->service->evaluate('nonexistent', new DateTimeImmutable('-30 days'), new DateTimeImmutable());
    }

    #[Test]
    public function evaluateCalculatesStepConversionRates(): void
    {
        $stepsJson = json_encode([
            ['position' => 1, 'name' => 'Landing', 'type' => 'page_visit', 'value' => '/'],
            ['position' => 2, 'name' => 'Signup', 'type' => 'page_visit', 'value' => '/signup'],
        ], JSON_THROW_ON_ERROR);

        $funnelRow = new Row([
            'id' => 'f-001',
            'site_id' => 'site-001',
            'name' => 'Test',
            'steps' => $stepsJson,
            'created_at' => '2025-06-01 12:00:00',
        ]);

        // First call returns the funnel definition.
        // Second call returns visitor IDs for step 1 (getStepVisitorIds).
        // Third call returns visitor IDs for step 2 (getSequentialStepVisitorIds).
        $funnelResult = new Result([$funnelRow]);

        $step1Visitors = array_map(
            static fn(int $i) => new Row(['visitor_id' => "v-$i"]),
            range(1, 100),
        );
        $step2Visitors = array_map(
            static fn(int $i) => new Row(['visitor_id' => "v-$i"]),
            range(1, 60),
        );

        $this->connection->method('query')
            ->willReturnOnConsecutiveCalls($funnelResult, new Result($step1Visitors), new Result($step2Visitors));

        $result = $this->service->evaluate('f-001', new DateTimeImmutable('-30 days'), new DateTimeImmutable());

        self::assertSame('f-001', $result->funnelId);
        self::assertCount(2, $result->steps);
        self::assertSame(100, $result->steps[0]->visitors);
        self::assertSame(60, $result->steps[1]->visitors);
        self::assertSame(60.0, $result->overallConversionRate);
    }

    #[Test]
    public function evaluateHandlesEmptyFunnel(): void
    {
        $stepsJson = json_encode([], JSON_THROW_ON_ERROR);

        $funnelRow = new Row([
            'id' => 'f-001',
            'site_id' => 'site-001',
            'name' => 'Empty',
            'steps' => $stepsJson,
            'created_at' => '2025-06-01 12:00:00',
        ]);

        $funnelResult = new Result([$funnelRow]);
        $this->connection->method('query')->willReturn($funnelResult);

        $result = $this->service->evaluate('f-001', new DateTimeImmutable('-30 days'), new DateTimeImmutable());

        self::assertSame(0.0, $result->overallConversionRate);
        self::assertSame([], $result->steps);
    }
}
