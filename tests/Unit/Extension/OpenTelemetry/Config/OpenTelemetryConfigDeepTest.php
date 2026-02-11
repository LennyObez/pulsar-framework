<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\OpenTelemetry\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Config\Environment;
use Pulsar\Extension\OpenTelemetry\Config\OpenTelemetryConfig;
use Pulsar\Extension\OpenTelemetry\Config\OtlpProtocol;

#[CoversClass(OpenTelemetryConfig::class)]
final class OpenTelemetryConfigDeepTest extends TestCase
{
    protected function setUp(): void
    {
        putenv('OTEL_EXPORTER_OTLP_ENDPOINT');
        putenv('OTEL_EXPORTER_OTLP_PROTOCOL');
        putenv('OTEL_SERVICE_NAME');
        putenv('OTEL_TRACES_SAMPLER');
        putenv('OTEL_TRACES_SAMPLER_ARG');
        putenv('OTEL_EXPORTER_OTLP_HEADERS');
    }

    protected function tearDown(): void
    {
        putenv('OTEL_EXPORTER_OTLP_ENDPOINT');
        putenv('OTEL_EXPORTER_OTLP_PROTOCOL');
        putenv('OTEL_SERVICE_NAME');
        putenv('OTEL_TRACES_SAMPLER');
        putenv('OTEL_TRACES_SAMPLER_ARG');
        putenv('OTEL_EXPORTER_OTLP_HEADERS');
    }

    #[Test]
    public function defaultValues(): void
    {
        $config = OpenTelemetryConfig::fromArray([]);

        self::assertFalse($config->enabled);
        self::assertSame('http://localhost:4318', $config->endpoint);
        self::assertSame(OtlpProtocol::HttpProtobuf, $config->protocol);
        self::assertSame(5000, $config->timeoutMs);
        self::assertSame([], $config->headers);
        self::assertSame('', $config->serviceName);
        self::assertSame('', $config->serviceVersion);
        self::assertSame('', $config->serviceNamespace);
        self::assertFalse($config->dualExport);
    }

    #[Test]
    public function nonBoolEnabledDefaultsToFalse(): void
    {
        $config = OpenTelemetryConfig::fromArray(['enabled' => 'yes']);

        self::assertFalse($config->enabled);
    }

    #[Test]
    public function nonStringEndpointDefaultsToLocalhost(): void
    {
        $config = OpenTelemetryConfig::fromArray(['endpoint' => 123]);

        self::assertSame('http://localhost:4318', $config->endpoint);
    }

    #[Test]
    public function invalidProtocolDefaultsToHttpProtobuf(): void
    {
        $config = OpenTelemetryConfig::fromArray(['protocol' => 'invalid']);

        self::assertSame(OtlpProtocol::HttpProtobuf, $config->protocol);
    }

    #[Test]
    public function nonStringProtocolDefaultsToHttpProtobuf(): void
    {
        $config = OpenTelemetryConfig::fromArray(['protocol' => 42]);

        self::assertSame(OtlpProtocol::HttpProtobuf, $config->protocol);
    }

    #[Test]
    public function nonIntTimeoutDefaultsTo5000(): void
    {
        $config = OpenTelemetryConfig::fromArray(['timeout_ms' => 'slow']);

        self::assertSame(5000, $config->timeoutMs);
    }

    #[Test]
    public function nonArrayHeadersDefaultsToEmpty(): void
    {
        $config = OpenTelemetryConfig::fromArray(['headers' => 'bad']);

        self::assertSame([], $config->headers);
    }

    #[Test]
    public function nonStringServiceNameDefaultsToEmpty(): void
    {
        $config = OpenTelemetryConfig::fromArray(['service_name' => 123]);

        self::assertSame('', $config->serviceName);
    }

    #[Test]
    public function nonStringServiceVersionDefaultsToEmpty(): void
    {
        $config = OpenTelemetryConfig::fromArray(['service_version' => true]);

        self::assertSame('', $config->serviceVersion);
    }

    #[Test]
    public function nonStringServiceNamespaceDefaultsToEmpty(): void
    {
        $config = OpenTelemetryConfig::fromArray(['service_namespace' => []]);

        self::assertSame('', $config->serviceNamespace);
    }

    #[Test]
    public function nonBoolDualExportDefaultsToFalse(): void
    {
        $config = OpenTelemetryConfig::fromArray(['dual_export' => 'true']);

        self::assertFalse($config->dualExport);
    }

    #[Test]
    public function nonArraySubConfigsDefaultToEmpty(): void
    {
        $config = OpenTelemetryConfig::fromArray([
            'traces' => 'bad',
            'metrics' => 123,
            'logs' => false,
            'sampler' => null,
            'batch' => 'no',
            'cardinality' => 42,
        ]);

        // Should not throw — all sub-configs should use defaults
        self::assertFalse($config->enabled);
    }

    #[Test]
    public function nonArrayPropagatorsDefaultsToTracecontextBaggage(): void
    {
        $config = OpenTelemetryConfig::fromArray(['propagators' => 'bad']);

        self::assertSame(['tracecontext', 'baggage'], $config->propagators);
    }

    // --- Environment variable overrides ---

    #[Test]
    public function envOverridesEndpoint(): void
    {
        putenv('OTEL_EXPORTER_OTLP_ENDPOINT=https://collector.example.com:4317');
        $env = Environment::load();

        $config = OpenTelemetryConfig::fromArray([], $env);

        self::assertSame('https://collector.example.com:4317', $config->endpoint);
    }

    #[Test]
    public function envOverridesProtocol(): void
    {
        putenv('OTEL_EXPORTER_OTLP_PROTOCOL=grpc');
        $env = Environment::load();

        $config = OpenTelemetryConfig::fromArray([], $env);

        self::assertSame(OtlpProtocol::Grpc, $config->protocol);
    }

    #[Test]
    public function envInvalidProtocolKeepsFileValue(): void
    {
        putenv('OTEL_EXPORTER_OTLP_PROTOCOL=invalid');
        $env = Environment::load();

        $config = OpenTelemetryConfig::fromArray(['protocol' => 'grpc'], $env);

        self::assertSame(OtlpProtocol::Grpc, $config->protocol);
    }

    #[Test]
    public function envOverridesServiceName(): void
    {
        putenv('OTEL_SERVICE_NAME=my-service');
        $env = Environment::load();

        $config = OpenTelemetryConfig::fromArray([], $env);

        self::assertSame('my-service', $config->serviceName);
    }

    #[Test]
    public function envOverridesSamplerAlwaysOn(): void
    {
        putenv('OTEL_TRACES_SAMPLER=always_on');
        $env = Environment::load();

        $config = OpenTelemetryConfig::fromArray([], $env);

        self::assertSame('always', $config->sampler->type->value);
    }

    #[Test]
    public function envOverridesSamplerAlwaysOff(): void
    {
        putenv('OTEL_TRACES_SAMPLER=always_off');
        $env = Environment::load();

        $config = OpenTelemetryConfig::fromArray([], $env);

        self::assertSame('never', $config->sampler->type->value);
    }

    #[Test]
    public function envOverridesSamplerTraceIdRatio(): void
    {
        putenv('OTEL_TRACES_SAMPLER=traceidratio');
        putenv('OTEL_TRACES_SAMPLER_ARG=0.5');
        $env = Environment::load();

        $config = OpenTelemetryConfig::fromArray([], $env);

        self::assertSame('probability', $config->sampler->type->value);
        self::assertEqualsWithDelta(0.5, $config->sampler->probability, 0.001);
    }

    #[Test]
    public function envOverridesSamplerParentBased(): void
    {
        putenv('OTEL_TRACES_SAMPLER=parentbased_always_on');
        $env = Environment::load();

        $config = OpenTelemetryConfig::fromArray([], $env);

        self::assertSame('parent_based', $config->sampler->type->value);
    }

    #[Test]
    public function envSamplerArgOutOfRangeKeepsDefault(): void
    {
        putenv('OTEL_TRACES_SAMPLER=traceidratio');
        putenv('OTEL_TRACES_SAMPLER_ARG=2.0');
        $env = Environment::load();

        $config = OpenTelemetryConfig::fromArray([], $env);

        self::assertEqualsWithDelta(1.0, $config->sampler->probability, 0.001);
    }

    #[Test]
    public function envOverridesHeaders(): void
    {
        putenv('OTEL_EXPORTER_OTLP_HEADERS=Authorization=Bearer token,X-Custom=value');
        $env = Environment::load();

        $config = OpenTelemetryConfig::fromArray([], $env);

        self::assertSame('Bearer token', $config->headers['Authorization']);
        self::assertSame('value', $config->headers['X-Custom']);
    }

    #[Test]
    public function parseHeaderStringSkipsEmptyPairs(): void
    {
        putenv('OTEL_EXPORTER_OTLP_HEADERS=key=val,,,,other=ok');
        $env = Environment::load();

        $config = OpenTelemetryConfig::fromArray([], $env);

        self::assertSame(['key' => 'val', 'other' => 'ok'], $config->headers);
    }

    #[Test]
    public function parseHeaderStringSkipsPairsWithoutEquals(): void
    {
        putenv('OTEL_EXPORTER_OTLP_HEADERS=key=val,noequals,other=ok');
        $env = Environment::load();

        $config = OpenTelemetryConfig::fromArray([], $env);

        self::assertCount(2, $config->headers);
        self::assertSame('val', $config->headers['key']);
        self::assertSame('ok', $config->headers['other']);
    }

    #[Test]
    public function parseHeaderStringRejectsHeaderInjection(): void
    {
        putenv("OTEL_EXPORTER_OTLP_HEADERS=good=val,evil\r\ninjection=bad");
        $env = Environment::load();

        $config = OpenTelemetryConfig::fromArray([], $env);

        self::assertArrayHasKey('good', $config->headers);
        self::assertArrayNotHasKey("evil\r\ninjection", $config->headers);
    }

    #[Test]
    public function envUnknownSamplerNameFallsBackToTryFrom(): void
    {
        putenv('OTEL_TRACES_SAMPLER=rate_limited');
        $env = Environment::load();

        $config = OpenTelemetryConfig::fromArray([], $env);

        self::assertSame('rate_limited', $config->sampler->type->value);
    }
}
