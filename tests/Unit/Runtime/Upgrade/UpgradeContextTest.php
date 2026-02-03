<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Runtime\Upgrade;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Pulsar\Config\RuntimeConfig;
use Pulsar\Observability\Metrics\MetricRegistry;
use Pulsar\Runtime\Upgrade\UpgradeContext;

#[CoversClass(UpgradeContext::class)]
final class UpgradeContextTest extends TestCase
{
    #[Test]
    public function it_defaults_to_null_dependencies(): void
    {
        $ctx = new UpgradeContext();

        self::assertNull($ctx->logger);
        self::assertNull($ctx->metrics);
        self::assertNull($ctx->config);
    }

    #[Test]
    public function it_accepts_optional_dependencies(): void
    {
        $logger = $this->createStub(LoggerInterface::class);
        $metrics = new MetricRegistry();
        $config = new RuntimeConfig();

        $ctx = new UpgradeContext(
            logger: $logger,
            metrics: $metrics,
            config: $config,
        );

        self::assertSame($logger, $ctx->logger);
        self::assertSame($metrics, $ctx->metrics);
        self::assertSame($config, $ctx->config);
    }
}
