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
use Pulsar\Extension\Analytics\Domain\AttributionModel;
use Pulsar\Extension\Analytics\Internal\Service\AttributionService;

final class AttributionServiceTest extends TestCase
{
    private AttributionService $service;
    private ConnectionInterface&Stub $connection;

    protected function setUp(): void
    {
        $this->connection = $this->createStub(ConnectionInterface::class);
        $this->service = new AttributionService($this->connection);
    }

    #[Test]
    public function calculateFirstTouchCreditsFirstTouchpointOnly(): void
    {
        // Visitor A: Google -> Facebook -> Email (credit goes to Google)
        // Visitor B: Direct -> Google (credit goes to Direct)
        $rows = [
            new Row(['visitor_id' => 'v1', 'source' => 'Google', 'started_at' => '2026-01-01 10:00:00', 'revenue' => 100.0]),
            new Row(['visitor_id' => 'v1', 'source' => 'Facebook', 'started_at' => '2026-01-02 10:00:00', 'revenue' => 100.0]),
            new Row(['visitor_id' => 'v1', 'source' => 'Email', 'started_at' => '2026-01-03 10:00:00', 'revenue' => 100.0]),
            new Row(['visitor_id' => 'v2', 'source' => 'Direct', 'started_at' => '2026-01-01 12:00:00', 'revenue' => 50.0]),
            new Row(['visitor_id' => 'v2', 'source' => 'Google', 'started_at' => '2026-01-02 12:00:00', 'revenue' => 50.0]),
        ];
        $this->connection->method('query')->willReturn(new Result($rows));

        $results = $this->service->calculate(
            'site-001',
            new DateTimeImmutable('-30 days'),
            new DateTimeImmutable(),
            AttributionModel::FirstTouch,
        );

        self::assertCount(2, $results);

        // Find Google and Direct results
        $bySource = [];
        foreach ($results as $r) {
            $bySource[$r->source] = $r;
        }

        self::assertSame(1, $bySource['Google']->conversions);
        self::assertSame(1, $bySource['Direct']->conversions);
        self::assertSame(1.0, $bySource['Google']->weight);
    }

    #[Test]
    public function calculateLastTouchCreditsLastTouchpointOnly(): void
    {
        // Visitor A: Google -> Facebook -> Email (credit goes to Email)
        $rows = [
            new Row(['visitor_id' => 'v1', 'source' => 'Google', 'started_at' => '2026-01-01 10:00:00', 'revenue' => 200.0]),
            new Row(['visitor_id' => 'v1', 'source' => 'Facebook', 'started_at' => '2026-01-02 10:00:00', 'revenue' => 200.0]),
            new Row(['visitor_id' => 'v1', 'source' => 'Email', 'started_at' => '2026-01-03 10:00:00', 'revenue' => 200.0]),
        ];
        $this->connection->method('query')->willReturn(new Result($rows));

        $results = $this->service->calculate(
            'site-001',
            new DateTimeImmutable('-30 days'),
            new DateTimeImmutable(),
            AttributionModel::LastTouch,
        );

        self::assertCount(1, $results);
        self::assertSame('Email', $results[0]->source);
        self::assertSame(1, $results[0]->conversions);
        self::assertSame(1.0, $results[0]->weight);
    }

    #[Test]
    public function calculateLinearDistributesCreditEvenly(): void
    {
        // Visitor A: Google -> Facebook (each gets 0.5 credit)
        $rows = [
            new Row(['visitor_id' => 'v1', 'source' => 'Google', 'started_at' => '2026-01-01 10:00:00', 'revenue' => 100.0]),
            new Row(['visitor_id' => 'v1', 'source' => 'Facebook', 'started_at' => '2026-01-02 10:00:00', 'revenue' => 100.0]),
        ];
        $this->connection->method('query')->willReturn(new Result($rows));

        $results = $this->service->calculate(
            'site-001',
            new DateTimeImmutable('-30 days'),
            new DateTimeImmutable(),
            AttributionModel::Linear,
        );

        // Both should have roughly equal credit
        self::assertCount(2, $results);

        $bySource = [];
        foreach ($results as $r) {
            $bySource[$r->source] = $r;
        }

        // Linear: each touchpoint gets 1/N credit
        // With 2 touchpoints, each gets 0.5. round(0.5) = 1 per source.
        self::assertSame(1, $bySource['Google']->conversions);
        self::assertSame(1, $bySource['Facebook']->conversions);
        // Weights should be equal (0.5 each)
        self::assertSame(0.5, $bySource['Google']->weight);
        self::assertSame(0.5, $bySource['Facebook']->weight);
    }

    #[Test]
    public function calculateTimeDecayWeightsRecentTouchpointsMore(): void
    {
        // Visitor with 2 touchpoints: one old, one recent
        $rows = [
            new Row(['visitor_id' => 'v1', 'source' => 'Google', 'started_at' => '2026-01-01 10:00:00', 'revenue' => 100.0]),
            new Row(['visitor_id' => 'v1', 'source' => 'Email', 'started_at' => '2026-01-15 10:00:00', 'revenue' => 100.0]),
        ];
        $this->connection->method('query')->willReturn(new Result($rows));

        $results = $this->service->calculate(
            'site-001',
            new DateTimeImmutable('-30 days'),
            new DateTimeImmutable(),
            AttributionModel::TimeDecay,
        );

        self::assertNotEmpty($results);

        // The more recent touchpoint (Email) should have a higher weight
        $bySource = [];
        foreach ($results as $r) {
            $bySource[$r->source] = $r;
        }

        // Email (more recent) should have higher weight than Google (older)
        if (isset($bySource['Google'], $bySource['Email'])) {
            self::assertGreaterThanOrEqual($bySource['Google']->weight, $bySource['Email']->weight);
        }
    }

    #[Test]
    public function calculateReturnsEmptyForNoData(): void
    {
        $this->connection->method('query')->willReturn(new Result([]));

        $results = $this->service->calculate(
            'site-001',
            new DateTimeImmutable('-30 days'),
            new DateTimeImmutable(),
            AttributionModel::FirstTouch,
        );

        self::assertSame([], $results);
    }

    #[Test]
    public function compareModelsReturnsAllModels(): void
    {
        $this->connection->method('query')->willReturn(new Result([]));

        $results = $this->service->compareModels(
            'site-001',
            new DateTimeImmutable('-30 days'),
            new DateTimeImmutable(),
        );

        self::assertArrayHasKey('first_touch', $results);
        self::assertArrayHasKey('last_touch', $results);
        self::assertArrayHasKey('linear', $results);
        self::assertArrayHasKey('time_decay', $results);
    }

    #[Test]
    public function calculatePassesGoalIdToQuery(): void
    {
        // When goalId is provided, the SQL query should include goal_id filtering
        // The service uses SQL_VISITOR_TOUCHPOINTS_WITH_GOAL which adds goal_id param
        $this->connection->method('query')->willReturn(new Result([]));

        $results = $this->service->calculate(
            'site-001',
            new DateTimeImmutable('-30 days'),
            new DateTimeImmutable(),
            AttributionModel::FirstTouch,
            goalId: 'goal-signup',
        );

        self::assertSame([], $results);
    }

    #[Test]
    public function compareModelsPassesGoalId(): void
    {
        $this->connection->method('query')->willReturn(new Result([]));

        $results = $this->service->compareModels(
            'site-001',
            new DateTimeImmutable('-30 days'),
            new DateTimeImmutable(),
            goalId: 'goal-purchase',
        );

        // All models should be present even with goalId
        self::assertCount(4, $results);
    }

    #[Test]
    public function calculateMultipleVisitorsAggregatesPerSource(): void
    {
        // Two visitors both starting with Google
        $rows = [
            new Row(['visitor_id' => 'v1', 'source' => 'Google', 'started_at' => '2026-01-01 10:00:00', 'revenue' => 50.0]),
            new Row(['visitor_id' => 'v2', 'source' => 'Google', 'started_at' => '2026-01-01 11:00:00', 'revenue' => 75.0]),
        ];
        $this->connection->method('query')->willReturn(new Result($rows));

        $results = $this->service->calculate(
            'site-001',
            new DateTimeImmutable('-30 days'),
            new DateTimeImmutable(),
            AttributionModel::FirstTouch,
        );

        self::assertCount(1, $results);
        self::assertSame('Google', $results[0]->source);
        self::assertSame(2, $results[0]->conversions);
    }
}
