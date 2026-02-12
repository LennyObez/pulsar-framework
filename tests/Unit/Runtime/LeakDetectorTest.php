<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Runtime;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Pulsar\Runtime\Exception\ResourceLeakException;
use Pulsar\Runtime\LeakDetector;

#[CoversClass(LeakDetector::class)]
final class LeakDetectorTest extends TestCase
{
    #[Test]
    public function it_reports_no_warnings_for_clean_request(): void
    {
        $detector = new LeakDetector();
        $detector->beginRequest();

        $warnings = $detector->endRequest();

        self::assertSame([], $warnings);
    }

    #[Test]
    public function it_detects_unreleased_resources(): void
    {
        $detector = new LeakDetector();
        $detector->beginRequest();

        $detector->trackResource('conn-1', 'database', 'MySQL connection');
        $detector->trackResource('stream-1', 'file', 'Log file handle');

        $warnings = $detector->endRequest();

        self::assertCount(2, $warnings);
        self::assertStringContainsString('conn-1', $warnings[0]);
        self::assertStringContainsString('database', $warnings[0]);
        self::assertStringContainsString('stream-1', $warnings[1]);
    }

    #[Test]
    public function it_does_not_warn_for_released_resources(): void
    {
        $detector = new LeakDetector();
        $detector->beginRequest();

        $detector->trackResource('conn-1', 'database', 'MySQL connection');
        $detector->releaseResource('conn-1');

        $warnings = $detector->endRequest();

        self::assertSame([], $warnings);
    }

    #[Test]
    public function it_clears_tracked_resources_on_begin_request(): void
    {
        $detector = new LeakDetector();

        // First request with leaked resource
        $detector->beginRequest();
        $detector->trackResource('conn-1', 'database', 'MySQL');

        // Second request starts fresh
        $detector->beginRequest();
        $warnings = $detector->endRequest();

        self::assertSame([], $warnings);
    }

    #[Test]
    public function it_snapshots_memory_baseline(): void
    {
        $detector = new LeakDetector();
        $detector->beginRequest();

        self::assertGreaterThan(0, $detector->memoryBaseline());
    }

    #[Test]
    public function it_returns_tracked_resources(): void
    {
        $detector = new LeakDetector();
        $detector->beginRequest();

        $detector->trackResource('res-1', 'socket', 'Client socket');

        $resources = $detector->trackedResources();
        self::assertCount(1, $resources);
        self::assertSame('res-1', $resources[0]->id);
        self::assertSame('socket', $resources[0]->type);
    }

    #[Test]
    public function it_logs_warnings_to_logger(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::atLeastOnce())->method('warning');

        $detector = new LeakDetector(logger: $logger);
        $detector->beginRequest();
        $detector->trackResource('leaked', 'test', 'Test resource');
        $detector->endRequest();
    }

    #[Test]
    public function strict_mode_throws_on_unreleased_resources(): void
    {
        $detector = new LeakDetector(strictMode: true);
        $detector->beginRequest();

        $detector->trackResource('conn-1', 'database', 'MySQL connection');

        $this->expectException(ResourceLeakException::class);
        $this->expectExceptionMessageMatches('/1 unclosed resource\(s\) detected/');

        $detector->endRequest();
    }

    #[Test]
    public function strict_mode_throws_with_multiple_unreleased_resources(): void
    {
        $detector = new LeakDetector(strictMode: true);
        $detector->beginRequest();

        $detector->trackResource('conn-1', 'database', 'MySQL connection');
        $detector->trackResource('stream-1', 'file', 'Log file handle');

        $this->expectException(ResourceLeakException::class);
        $this->expectExceptionMessageMatches('/2 unclosed resource\(s\) detected/');

        $detector->endRequest();
    }

    #[Test]
    public function strict_mode_clears_resources_after_throwing(): void
    {
        $detector = new LeakDetector(strictMode: true);
        $detector->beginRequest();

        $detector->trackResource('conn-1', 'database', 'MySQL connection');

        try {
            $detector->endRequest();
        } catch (ResourceLeakException) {
            // Expected
        }

        // Resources should be cleared after the exception
        self::assertSame([], $detector->trackedResources());
    }

    #[Test]
    public function strict_mode_does_not_throw_when_all_resources_released(): void
    {
        $detector = new LeakDetector(strictMode: true);
        $detector->beginRequest();

        $detector->trackResource('conn-1', 'database', 'MySQL connection');
        $detector->releaseResource('conn-1');

        $warnings = $detector->endRequest();

        self::assertSame([], $warnings);
    }
}
