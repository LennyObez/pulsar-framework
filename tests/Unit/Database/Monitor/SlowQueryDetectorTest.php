<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Database\Monitor;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Pulsar\Database\Monitor\MonitorConfig;
use Pulsar\Database\Monitor\QueryClassifier;
use Pulsar\Database\Monitor\SlowQueryDetector;

#[CoversClass(SlowQueryDetector::class)]
#[CoversClass(QueryClassifier::class)]
final class SlowQueryDetectorTest extends TestCase
{
    #[Test]
    public function fastQueryNotFlagged(): void
    {
        $config = new MonitorConfig(slowQueryThresholdMs: 1000);
        $detector = new SlowQueryDetector($config);

        self::assertFalse($detector->check('SELECT 1', 500.0));
    }

    #[Test]
    public function slowQueryFlagged(): void
    {
        $config = new MonitorConfig(slowQueryThresholdMs: 1000);
        $detector = new SlowQueryDetector($config);

        self::assertTrue($detector->check('SELECT * FROM users', 1500.0));
    }

    #[Test]
    public function queryAtExactThreshold(): void
    {
        $config = new MonitorConfig(slowQueryThresholdMs: 1000);
        $detector = new SlowQueryDetector($config);

        self::assertTrue($detector->check('SELECT 1', 1000.0));
    }

    #[Test]
    public function getThresholdReturnsConfiguredValue(): void
    {
        $config = new MonitorConfig(slowQueryThresholdMs: 500);
        $detector = new SlowQueryDetector($config);

        self::assertSame(500.0, $detector->getThresholdMs());
    }

    #[Test]
    public function slowQueryLoggedAsWarning(): void
    {
        $config = new MonitorConfig(slowQueryThresholdMs: 100);
        $logger = $this->createMock(LoggerInterface::class);

        $logger->expects(self::once())
            ->method('warning')
            ->with(
                'Slow query detected',
                self::callback(static function (array $context): bool {
                    return isset($context['sql'], $context['duration_ms'], $context['threshold_ms'], $context['classification'])
                        && $context['duration_ms'] === 200.0
                        && $context['threshold_ms'] === 100
                        && $context['classification'] === 'select';
                }),
            );

        $detector = new SlowQueryDetector($config, $logger);
        $detector->check('SELECT * FROM users', 200.0);
    }
}
