<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\AuditConfig;
use Pulsar\Config\Environment;
use Pulsar\Config\ErrorTrackingConfig;
use Pulsar\Config\LoggingChannelConfig;
use Pulsar\Config\MetricsConfig;
use Pulsar\Config\ObservabilityConfig;
use Pulsar\Config\TracingConfig;

#[CoversClass(ObservabilityConfig::class)]
#[CoversClass(MetricsConfig::class)]
#[CoversClass(TracingConfig::class)]
#[CoversClass(ErrorTrackingConfig::class)]
#[CoversClass(AuditConfig::class)]
#[CoversClass(LoggingChannelConfig::class)]
final class ObservabilityConfigTest extends TestCase
{
    private Environment $env;

    protected function setUp(): void
    {
        putenv('LOG_CHANNEL');
        putenv('LOG_LEVEL');
        putenv('AUDIT_LOG_PATH');
        $this->env = Environment::load();
    }

    protected function tearDown(): void
    {
        putenv('LOG_CHANNEL');
        putenv('LOG_LEVEL');
        putenv('AUDIT_LOG_PATH');
    }

    #[Test]
    public function defaultsApplied(): void
    {
        $config = ObservabilityConfig::fromArray([], $this->env);

        self::assertSame('file', $config->defaultLoggingChannel);
        self::assertSame('info', $config->loggingLevel);
        self::assertSame([], $config->loggingChannels);
        self::assertTrue($config->metrics->enabled);
        self::assertFalse($config->metrics->prometheusEnabled);
        self::assertSame('/metrics', $config->metrics->prometheusEndpoint);
        self::assertFalse($config->tracing->enabled);
        self::assertSame(0.1, $config->tracing->samplingRate);
        self::assertTrue($config->errorTracking->enabled);
        self::assertSame(500, $config->errorTracking->maxGroups);
        self::assertSame(5, $config->errorTracking->maxRecentEventsPerGroup);
        self::assertSame([], $config->errorTracking->sensitiveFields);
        self::assertTrue($config->audit->enabled);
        self::assertSame('var/logs/audit.jsonl', $config->audit->logPath);
        self::assertSame([], $config->audit->events);
    }

    #[Test]
    public function fromArrayBuildsAllSubConfigs(): void
    {
        $data = [
            'logging' => [
                'default_channel' => 'stderr',
                'level' => 'debug',
                'channels' => [
                    'file' => ['driver' => 'file', 'path' => '/var/log/app.log'],
                    'stderr' => ['driver' => 'stream', 'stream' => 'php://stderr'],
                ],
            ],
            'metrics' => [
                'enabled' => true,
                'exporters' => [
                    'prometheus' => ['enabled' => true, 'endpoint' => '/prom'],
                ],
            ],
            'tracing' => [
                'enabled' => true,
                'sampling_rate' => 0.5,
            ],
            'error_tracking' => [
                'enabled' => false,
                'max_groups' => 100,
                'max_recent_events_per_group' => 10,
                'sensitive_fields' => ['ssn', 'credit_card'],
            ],
            'audit' => [
                'enabled' => false,
                'log_path' => '/custom/audit.log',
                'events' => ['login', 'logout'],
            ],
        ];

        $config = ObservabilityConfig::fromArray($data, $this->env);

        self::assertSame('stderr', $config->defaultLoggingChannel);
        self::assertSame('debug', $config->loggingLevel);
        self::assertCount(2, $config->loggingChannels);

        $fileChannel = $config->loggingChannels[0];
        self::assertSame('file', $fileChannel->name);
        self::assertSame('file', $fileChannel->driver);
        self::assertSame('/var/log/app.log', $fileChannel->path);
        self::assertNull($fileChannel->stream);

        $stderrChannel = $config->loggingChannels[1];
        self::assertSame('stderr', $stderrChannel->name);
        self::assertSame('stream', $stderrChannel->driver);
        self::assertSame('php://stderr', $stderrChannel->stream);

        self::assertTrue($config->metrics->enabled);
        self::assertTrue($config->metrics->prometheusEnabled);
        self::assertSame('/prom', $config->metrics->prometheusEndpoint);

        self::assertTrue($config->tracing->enabled);
        self::assertSame(0.5, $config->tracing->samplingRate);

        self::assertFalse($config->errorTracking->enabled);
        self::assertSame(100, $config->errorTracking->maxGroups);
        self::assertSame(10, $config->errorTracking->maxRecentEventsPerGroup);
        self::assertSame(['ssn', 'credit_card'], $config->errorTracking->sensitiveFields);

        self::assertFalse($config->audit->enabled);
        self::assertSame('/custom/audit.log', $config->audit->logPath);
        self::assertSame(['login', 'logout'], $config->audit->events);
    }

    #[Test]
    public function envOverridesLoggingChannelAndLevel(): void
    {
        putenv('LOG_CHANNEL=syslog');
        putenv('LOG_LEVEL=warning');
        $env = Environment::load();

        $config = ObservabilityConfig::fromArray([
            'logging' => ['default_channel' => 'file', 'level' => 'info'],
        ], $env);

        self::assertSame('syslog', $config->defaultLoggingChannel);
        self::assertSame('warning', $config->loggingLevel);
    }

    #[Test]
    public function envOverridesAuditLogPath(): void
    {
        putenv('AUDIT_LOG_PATH=/env/audit.log');
        $env = Environment::load();

        $config = ObservabilityConfig::fromArray([
            'audit' => ['log_path' => '/file/audit.log'],
        ], $env);

        self::assertSame('/env/audit.log', $config->audit->logPath);
    }

    #[Test]
    public function metricsFromArrayStandalone(): void
    {
        $config = MetricsConfig::fromArray([
            'enabled' => false,
            'exporters' => [
                'prometheus' => ['enabled' => true, 'endpoint' => '/custom'],
            ],
        ]);

        self::assertFalse($config->enabled);
        self::assertTrue($config->prometheusEnabled);
        self::assertSame('/custom', $config->prometheusEndpoint);
    }

    #[Test]
    public function tracingFromArrayHandlesNonNumericRate(): void
    {
        $config = TracingConfig::fromArray(['sampling_rate' => 'invalid']);
        self::assertSame(0.1, $config->samplingRate);

        $config = TracingConfig::fromArray(['sampling_rate' => '0.75']);
        self::assertSame(0.75, $config->samplingRate);

        $config = TracingConfig::fromArray(['sampling_rate' => 1]);
        self::assertSame(1.0, $config->samplingRate);
    }

    #[Test]
    public function errorTrackingFromArrayHandlesNumericStrings(): void
    {
        $config = ErrorTrackingConfig::fromArray([
            'max_groups' => '200',
            'max_recent_events_per_group' => '15',
        ]);

        self::assertSame(200, $config->maxGroups);
        self::assertSame(15, $config->maxRecentEventsPerGroup);
    }

    #[Test]
    public function errorTrackingFromArrayHandlesNonNumeric(): void
    {
        $config = ErrorTrackingConfig::fromArray([
            'max_groups' => 'invalid',
            'max_recent_events_per_group' => 'bad',
        ]);

        self::assertSame(500, $config->maxGroups);
        self::assertSame(5, $config->maxRecentEventsPerGroup);
    }
}
