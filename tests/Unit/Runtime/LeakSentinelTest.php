<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Runtime;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Pulsar\Http\Message\ServerRequest;
use Pulsar\Runtime\LeakSentinel;
use Pulsar\Runtime\LeakSentinelConfig;
use Pulsar\Runtime\LeakSentinelReport;

#[CoversClass(LeakSentinel::class)]
final class LeakSentinelTest extends TestCase
{
    #[Test]
    public function it_runs_handler_for_each_request(): void
    {
        $callCount = 0;
        $handler = static function (ServerRequestInterface $request) use (&$callCount): void {
            $callCount++;
        };

        $sentinel = new LeakSentinel($this->createFixtures());
        $config = new LeakSentinelConfig(
            totalRequests: 10,
            snapshotPoints: [5, 10],
        );

        $sentinel->run($handler, $config);

        self::assertSame(10, $callCount);
    }

    #[Test]
    public function it_cycles_through_fixtures_deterministically(): void
    {
        /** @var list<string> $paths */
        $paths = [];
        $handler = static function (ServerRequestInterface $request) use (&$paths): void {
            $paths[] = $request->getUri()->getPath();
        };

        $fixtures = [
            $this->createRequest('/a'),
            $this->createRequest('/b'),
            $this->createRequest('/c'),
        ];

        $sentinel = new LeakSentinel($fixtures);
        $config = new LeakSentinelConfig(
            totalRequests: 7,
            snapshotPoints: [7],
        );

        $sentinel->run($handler, $config);

        self::assertSame(['/a', '/b', '/c', '/a', '/b', '/c', '/a'], $paths);
    }

    #[Test]
    public function it_takes_snapshots_at_configured_points(): void
    {
        $handler = static function (ServerRequestInterface $request): void {
            // no-op handler
        };

        $sentinel = new LeakSentinel($this->createFixtures());
        $config = new LeakSentinelConfig(
            totalRequests: 20,
            snapshotPoints: [5, 10, 15, 20],
        );

        $report = $sentinel->run($handler, $config);

        self::assertArrayHasKey(5, $report->snapshots);
        self::assertArrayHasKey(10, $report->snapshots);
        self::assertArrayHasKey(15, $report->snapshots);
        self::assertArrayHasKey(20, $report->snapshots);
        self::assertCount(4, $report->snapshots);
    }

    #[Test]
    public function it_returns_a_report(): void
    {
        $handler = static function (ServerRequestInterface $request): void {
            // no-op handler
        };

        $sentinel = new LeakSentinel($this->createFixtures());
        $config = new LeakSentinelConfig(
            totalRequests: 10,
            snapshotPoints: [5, 10],
        );

        $report = $sentinel->run($handler, $config);

        self::assertInstanceOf(LeakSentinelReport::class, $report);
        self::assertNotEmpty($report->summary);
    }

    #[Test]
    public function it_passes_correct_request_to_handler(): void
    {
        $fixture = $this->createRequest('/test-path');
        /** @var ServerRequestInterface|null $received */
        $received = null;
        $handler = static function (ServerRequestInterface $request) use (&$received): void {
            $received = $request;
        };

        $sentinel = new LeakSentinel([$fixture]);
        $config = new LeakSentinelConfig(
            totalRequests: 1,
            snapshotPoints: [1],
        );

        $sentinel->run($handler, $config);

        self::assertNotNull($received);
        self::assertSame('/test-path', $received->getUri()->getPath());
    }

    private function createRequest(string $path): ServerRequestInterface
    {
        return new ServerRequest(
            method: 'GET',
            uri: $path,
        );
    }

    /** @return list<ServerRequestInterface> */
    private function createFixtures(): array
    {
        return [
            $this->createRequest('/'),
            $this->createRequest('/api/health'),
        ];
    }
}
