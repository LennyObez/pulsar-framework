<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Observability;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Observability\HttpClientInstrumentation;
use Pulsar\Observability\Metrics\MetricRegistry;
use Pulsar\Observability\OutboundRequestContext;
use RuntimeException;

#[CoversClass(HttpClientInstrumentation::class)]
#[CoversClass(OutboundRequestContext::class)]
final class HttpClientInstrumentationTest extends TestCase
{
    private MetricRegistry $metrics;
    private HttpClientInstrumentation $instrumentation;

    protected function setUp(): void
    {
        $this->metrics = new MetricRegistry();
        $this->instrumentation = new HttpClientInstrumentation($this->metrics);
    }

    #[Test]
    public function startRecordsRequestCounter(): void
    {
        $ctx = $this->instrumentation->start('GET', 'https://api.example.com/users');

        self::assertSame('GET', $ctx->method);
        self::assertSame('api.example.com', $ctx->host);
        self::assertSame('https', $ctx->scheme);

        self::assertArrayHasKey('http_client_requests_total', $this->metrics->all());
    }

    #[Test]
    public function finishRecordsDurationHistogram(): void
    {
        $ctx = $this->instrumentation->start('POST', 'https://api.example.com/data');
        $this->instrumentation->finish($ctx, 200);

        self::assertArrayHasKey('http_client_duration_ms', $this->metrics->all());
    }

    #[Test]
    public function finishRecordsErrorCounterFor5xx(): void
    {
        $ctx = $this->instrumentation->start('GET', 'https://api.example.com/fail');
        $this->instrumentation->finish($ctx, 503);

        self::assertArrayHasKey('http_client_errors_total', $this->metrics->all());
    }

    #[Test]
    public function finishDoesNotRecordErrorCounterFor2xx(): void
    {
        $ctx = $this->instrumentation->start('GET', 'https://api.example.com/ok');
        $this->instrumentation->finish($ctx, 200);

        self::assertArrayNotHasKey('http_client_errors_total', $this->metrics->all());
    }

    #[Test]
    public function errorRecordsBothDurationAndErrorCounter(): void
    {
        $ctx = $this->instrumentation->start('PUT', 'https://api.example.com/update');
        $this->instrumentation->error($ctx, new RuntimeException('Connection timeout'));

        $all = $this->metrics->all();

        self::assertArrayHasKey('http_client_duration_ms', $all);
        self::assertArrayHasKey('http_client_errors_total', $all);
    }

    #[Test]
    public function startHandlesUrlWithoutHost(): void
    {
        $ctx = $this->instrumentation->start('GET', '/relative/path');

        self::assertSame('unknown', $ctx->host);
    }

    #[Test]
    public function startNormalizesMethodToUppercase(): void
    {
        $ctx = $this->instrumentation->start('get', 'https://example.com');

        self::assertSame('GET', $ctx->method);
    }

    #[Test]
    public function contextHoldsStartTime(): void
    {
        $before = hrtime(true);
        $ctx = $this->instrumentation->start('GET', 'https://example.com');
        $after = hrtime(true);

        self::assertGreaterThanOrEqual($before, $ctx->startTimeNs);
        self::assertLessThanOrEqual($after, $ctx->startTimeNs);
    }
}
