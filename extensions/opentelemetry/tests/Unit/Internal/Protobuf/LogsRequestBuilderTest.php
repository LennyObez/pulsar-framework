<?php

declare(strict_types=1);

namespace Pulsar\Extension\OpenTelemetry\Tests\Unit\Internal\Protobuf;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\OpenTelemetry\Internal\Protobuf\LogsRequestBuilder;
use Pulsar\Extension\OpenTelemetry\Internal\Protobuf\OtlpLogRecord;
use Pulsar\Extension\OpenTelemetry\Internal\Protobuf\ResourceInfo;

use function strlen;

#[CoversClass(LogsRequestBuilder::class)]
final class LogsRequestBuilderTest extends TestCase
{
    #[Test]
    public function buildReturnsEmptyStringForEmptyRecords(): void
    {
        $builder = new LogsRequestBuilder();
        $result = $builder->build([], new ResourceInfo());

        self::assertSame('', $result);
    }

    #[Test]
    public function buildReturnsNonEmptyBytesForSingleRecord(): void
    {
        $builder = new LogsRequestBuilder();
        $record = new OtlpLogRecord(
            timeUnixNano: 1000000000,
            severityNumber: 17,
            severityText: 'ERROR',
            body: 'Test message',
            attributes: ['key' => 'value'],
            traceId: null,
            spanId: null,
        );

        $result = $builder->build([$record], new ResourceInfo(['service.name' => 'test']));

        self::assertNotSame('', $result);
        // Payload is non-empty binary protobuf
        self::assertGreaterThan(0, strlen($result));
    }

    #[Test]
    public function buildIncludesResourceAttributesInOutput(): void
    {
        $builder = new LogsRequestBuilder();
        $record = new OtlpLogRecord(
            timeUnixNano: 1000000000,
            severityNumber: 9,
            severityText: 'INFO',
            body: 'info log',
            attributes: [],
            traceId: null,
            spanId: null,
        );

        $withResource = $builder->build([$record], new ResourceInfo(['service.name' => 'my-app']));
        $withoutResource = $builder->build([$record], new ResourceInfo());

        // With resource attributes should produce a larger payload
        self::assertGreaterThan(strlen($withoutResource), strlen($withResource));
    }

    #[Test]
    public function buildWithMultipleRecordsProducesLargerPayload(): void
    {
        $builder = new LogsRequestBuilder();
        $record = new OtlpLogRecord(
            timeUnixNano: 1000000000,
            severityNumber: 17,
            severityText: 'ERROR',
            body: 'message',
            attributes: [],
            traceId: null,
            spanId: null,
        );

        $single = $builder->build([$record], new ResourceInfo());
        $double = $builder->build([$record, $record], new ResourceInfo());

        self::assertGreaterThan(strlen($single), strlen($double));
    }

    #[Test]
    public function buildIncludesTraceIdWhenPresent(): void
    {
        $builder = new LogsRequestBuilder();
        $traceId = str_repeat("\xAB", 16);

        $recordWithTrace = new OtlpLogRecord(
            timeUnixNano: 1000000000,
            severityNumber: 17,
            severityText: 'ERROR',
            body: 'correlated',
            attributes: [],
            traceId: $traceId,
            spanId: str_repeat("\xCD", 8),
        );

        $recordWithoutTrace = new OtlpLogRecord(
            timeUnixNano: 1000000000,
            severityNumber: 17,
            severityText: 'ERROR',
            body: 'not correlated',
            attributes: [],
            traceId: null,
            spanId: null,
        );

        $withTrace = $builder->build([$recordWithTrace], new ResourceInfo());
        $withoutTrace = $builder->build([$recordWithoutTrace], new ResourceInfo());

        // Trace-correlated log should have additional bytes for trace/span IDs
        self::assertGreaterThan(strlen($withoutTrace), strlen($withTrace));
    }

    #[Test]
    public function buildIncludesSeverityText(): void
    {
        $builder = new LogsRequestBuilder();
        $record = new OtlpLogRecord(
            timeUnixNano: 1000000000,
            severityNumber: 17,
            severityText: 'ERROR',
            body: 'test',
            attributes: [],
            traceId: null,
            spanId: null,
        );

        $result = $builder->build([$record], new ResourceInfo());

        // The payload should contain the severity text
        self::assertStringContainsString('ERROR', $result);
    }

    #[Test]
    public function buildWithEmptySeverityTextSkipsField(): void
    {
        $builder = new LogsRequestBuilder();
        $recordEmpty = new OtlpLogRecord(
            timeUnixNano: 1000000000,
            severityNumber: 17,
            severityText: '',
            body: 'test',
            attributes: [],
            traceId: null,
            spanId: null,
        );

        $recordWithText = new OtlpLogRecord(
            timeUnixNano: 1000000000,
            severityNumber: 17,
            severityText: 'ERROR',
            body: 'test',
            attributes: [],
            traceId: null,
            spanId: null,
        );

        $withEmpty = $builder->build([$recordEmpty], new ResourceInfo());
        $withText = $builder->build([$recordWithText], new ResourceInfo());

        // Without severity text should be shorter
        self::assertLessThan(strlen($withText), strlen($withEmpty));
    }

    #[Test]
    public function buildUsesCustomScopeNameAndVersion(): void
    {
        $builderDefault = new LogsRequestBuilder();
        $builderCustom = new LogsRequestBuilder(scopeName: 'custom-scope', scopeVersion: '2.0.0');

        $record = new OtlpLogRecord(
            timeUnixNano: 1000000000,
            severityNumber: 9,
            severityText: 'INFO',
            body: 'test',
            attributes: [],
            traceId: null,
            spanId: null,
        );

        $defaultResult = $builderDefault->build([$record], new ResourceInfo());
        $customResult = $builderCustom->build([$record], new ResourceInfo());

        // Different scope names/versions produce different bytes
        self::assertNotSame($defaultResult, $customResult);
    }

    #[Test]
    public function buildIncludesAttributes(): void
    {
        $builder = new LogsRequestBuilder();
        $recordWithAttrs = new OtlpLogRecord(
            timeUnixNano: 1000000000,
            severityNumber: 17,
            severityText: 'ERROR',
            body: 'test',
            attributes: ['http.method' => 'GET', 'http.status_code' => 200],
            traceId: null,
            spanId: null,
        );

        $recordWithoutAttrs = new OtlpLogRecord(
            timeUnixNano: 1000000000,
            severityNumber: 17,
            severityText: 'ERROR',
            body: 'test',
            attributes: [],
            traceId: null,
            spanId: null,
        );

        $withAttrs = $builder->build([$recordWithAttrs], new ResourceInfo());
        $withoutAttrs = $builder->build([$recordWithoutAttrs], new ResourceInfo());

        self::assertGreaterThan(strlen($withoutAttrs), strlen($withAttrs));
    }
}
