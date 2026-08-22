<?php

declare(strict_types=1);

namespace Pulsar\Extension\Observability\Tests\Unit\Integration;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Observability\Config\ObservabilityConfig;
use Pulsar\Extension\Observability\Integration\StudioPanel;

#[CoversClass(StudioPanel::class)]
final class StudioPanelTest extends TestCase
{
    #[Test]
    public function statusReportsDisabledWhenOtlpOff(): void
    {
        $config = ObservabilityConfig::fromArray([
            'enabled' => false,
            'traces' => ['enabled' => false],
            'metrics' => ['enabled' => false],
            'logs' => ['enabled' => false],
        ]);
        $panel = new StudioPanel($config);

        $status = $panel->status();

        self::assertFalse($status['otlp_enabled']);
        self::assertNull($status['otlp_endpoint']);
        self::assertNull($status['otlp_protocol']);
        self::assertFalse($status['traces_enabled']);
        self::assertFalse($status['metrics_enabled']);
        self::assertFalse($status['logs_enabled']);
    }

    #[Test]
    public function statusReportsEnabledWithEndpoint(): void
    {
        $config = ObservabilityConfig::fromArray([
            'enabled' => true,
            'endpoint' => 'http://collector:4318',
            'traces' => ['enabled' => true],
            'metrics' => ['enabled' => true],
            'logs' => ['enabled' => true],
        ]);

        $panel = new StudioPanel($config);
        $status = $panel->status();

        self::assertTrue($status['otlp_enabled']);
        self::assertSame('http://collector:4318', $status['otlp_endpoint']);
        self::assertTrue($status['traces_enabled']);
        self::assertTrue($status['metrics_enabled']);
        self::assertTrue($status['logs_enabled']);
    }

    #[Test]
    public function statusReportsQueueSizeZeroWithoutExporter(): void
    {
        $config = ObservabilityConfig::fromArray(['enabled' => true]);
        $panel = new StudioPanel($config);

        self::assertSame(0, $panel->status()['span_queue_size']);
    }

    #[Test]
    public function statusReportsSamplerType(): void
    {
        $config = ObservabilityConfig::fromArray([
            'enabled' => true,
            'sampler' => ['type' => 'probability', 'probability' => 0.5],
        ]);
        $panel = new StudioPanel($config);

        self::assertSame('probability', $panel->status()['sampler_type']);
    }

    #[Test]
    public function statusReportsDualExportSetting(): void
    {
        $config = ObservabilityConfig::fromArray(['enabled' => true, 'dual_export' => true]);
        $panel = new StudioPanel($config);

        self::assertTrue($panel->status()['dual_export']);
    }

    #[Test]
    public function statusReportsJsonLinesEnabled(): void
    {
        $config = ObservabilityConfig::fromArray([
            'enabled' => false,
            'export' => ['enabled' => true],
        ]);
        $panel = new StudioPanel($config);

        self::assertTrue($panel->status()['jsonlines_enabled']);
    }
}
