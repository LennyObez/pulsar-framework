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
use Pulsar\Extension\Analytics\Internal\Service\FlowService;

final class FlowServiceTest extends TestCase
{
    private FlowService $service;
    private ConnectionInterface&Stub $connection;

    protected function setUp(): void
    {
        $this->connection = $this->createStub(ConnectionInterface::class);
        $this->service = new FlowService($this->connection);
    }

    #[Test]
    public function getFlowFromPageReturnsEmptyForNoData(): void
    {
        $queryResult = new Result([]);
        $this->connection->method('query')->willReturn($queryResult);

        $result = $this->service->getFlowFromPage(
            'site-001',
            new DateTimeImmutable('-30 days'),
            new DateTimeImmutable(),
        );

        self::assertSame([], $result);
    }

    #[Test]
    public function getFlowFromPageReturnsFlowSteps(): void
    {
        $rows = [
            new Row(['source' => '/', 'target' => '/about', 'visitors' => 150]),
            new Row(['source' => '/', 'target' => '/pricing', 'visitors' => 80]),
        ];
        $queryResult = new Result($rows);
        $this->connection->method('query')->willReturn($queryResult);

        $result = $this->service->getFlowFromPage(
            'site-001',
            new DateTimeImmutable('-30 days'),
            new DateTimeImmutable(),
            '/',
            1,
        );

        self::assertCount(2, $result);
        self::assertSame('/', $result[0]->source);
        self::assertSame('/about', $result[0]->target);
        self::assertSame(150, $result[0]->visitors);
        self::assertSame(0, $result[0]->depth);
    }

    #[Test]
    public function getFlowFromPageExpandsDeepLevels(): void
    {
        // First call: top-level flow from /
        $level0Rows = [
            new Row(['source' => '/', 'target' => '/products', 'visitors' => 100]),
        ];
        $level0Result = new Result($level0Rows);

        // Second call: deeper flow from /products
        $level1Rows = [
            new Row(['source' => '/products', 'target' => '/cart', 'visitors' => 40]),
        ];
        $level1Result = new Result($level1Rows);

        $this->connection->method('query')
            ->willReturnOnConsecutiveCalls($level0Result, $level1Result);

        $result = $this->service->getFlowFromPage(
            'site-001',
            new DateTimeImmutable('-30 days'),
            new DateTimeImmutable(),
            '/',
            2,
        );

        self::assertCount(2, $result);
        self::assertSame(0, $result[0]->depth);
        self::assertSame(1, $result[1]->depth);
        self::assertSame('/products', $result[1]->source);
        self::assertSame('/cart', $result[1]->target);
    }

    #[Test]
    public function getExitPagesReturnsEmptyForNoData(): void
    {
        $queryResult = new Result([]);
        $this->connection->method('query')->willReturn($queryResult);

        $result = $this->service->getExitPages(
            'site-001',
            new DateTimeImmutable('-30 days'),
            new DateTimeImmutable(),
        );

        self::assertSame([], $result);
    }

    #[Test]
    public function getExitPagesReturnsExitPagesWithRates(): void
    {
        $rows = [
            new Row(['pathname' => '/checkout', 'exits' => 50, 'exit_rate' => 35.1234]),
            new Row(['pathname' => '/contact', 'exits' => 30, 'exit_rate' => 21.05]),
        ];
        $queryResult = new Result($rows);
        $this->connection->method('query')->willReturn($queryResult);

        $result = $this->service->getExitPages(
            'site-001',
            new DateTimeImmutable('-30 days'),
            new DateTimeImmutable(),
        );

        self::assertCount(2, $result);
        self::assertSame('/checkout', $result[0]['pathname']);
        self::assertSame(50, $result[0]['exits']);
        self::assertSame(35.1, $result[0]['exit_rate']);
    }

    #[Test]
    public function getFlowFromPageRespectsLimit(): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->expects(self::once())
            ->method('query')
            ->with(
                self::anything(),
                self::callback(static fn(array $params): bool => $params['limit'] === 15),
            )
            ->willReturn(new Result([]));

        $service = new FlowService($connection);

        $service->getFlowFromPage(
            'site-001',
            new DateTimeImmutable('-30 days'),
            new DateTimeImmutable(),
            '/',
            1,
            15,
        );
    }
}
