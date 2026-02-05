<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Studio\Console\Redaction;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Studio\Console\Event\EventType;
use Pulsar\Studio\Console\Redaction\DefaultRedactionPolicy;
use Pulsar\Studio\Console\Redaction\RedactionPipeline;
use Pulsar\Studio\Console\Redaction\RedactionPolicyInterface;

#[CoversClass(RedactionPipeline::class)]
final class RedactionPipelineTest extends TestCase
{
    #[Test]
    public function redactAppliesGlobalPolicies(): void
    {
        $pipeline = new RedactionPipeline();
        $pipeline->addGlobalPolicy($this->createMockPolicy(['field1' => 'GLOBAL']));

        $result = $pipeline->redact(
            ['field1' => 'value1', 'field2' => 'value2'],
            EventType::LogEntry,
        );

        self::assertSame('GLOBAL', $result['field1']);
        self::assertSame('value2', $result['field2']);
    }

    #[Test]
    public function redactAppliesMultipleGlobalPolicies(): void
    {
        $pipeline = new RedactionPipeline();
        $pipeline->addGlobalPolicy($this->createMockPolicy(['field1' => 'POLICY1']));
        $pipeline->addGlobalPolicy($this->createMockPolicy(['field2' => 'POLICY2']));

        $result = $pipeline->redact(
            ['field1' => 'value1', 'field2' => 'value2', 'field3' => 'value3'],
            EventType::LogEntry,
        );

        self::assertSame('POLICY1', $result['field1']);
        self::assertSame('POLICY2', $result['field2']);
        self::assertSame('value3', $result['field3']);
    }

    #[Test]
    public function redactAppliesTypePolicies(): void
    {
        $pipeline = new RedactionPipeline();
        $pipeline->addTypePolicy(
            EventType::HttpRequest,
            $this->createMockPolicy(['request_field' => 'REDACTED']),
        );

        $result = $pipeline->redact(
            ['request_field' => 'value', 'other' => 'kept'],
            EventType::HttpRequest,
        );

        self::assertSame('REDACTED', $result['request_field']);
        self::assertSame('kept', $result['other']);
    }

    #[Test]
    public function redactSkipsTypePoliciesForOtherTypes(): void
    {
        $pipeline = new RedactionPipeline();
        $pipeline->addTypePolicy(
            EventType::HttpRequest,
            $this->createMockPolicy(['request_field' => 'REDACTED']),
        );

        $result = $pipeline->redact(
            ['request_field' => 'value', 'other' => 'kept'],
            EventType::LogEntry, // Different event type
        );

        // Type policy should not apply
        self::assertSame('value', $result['request_field']);
        self::assertSame('kept', $result['other']);
    }

    #[Test]
    public function redactAppliesGlobalBeforeTypePolicies(): void
    {
        $pipeline = new RedactionPipeline();
        $pipeline->addGlobalPolicy($this->createMockPolicy(['field' => 'GLOBAL']));
        $pipeline->addTypePolicy(
            EventType::HttpRequest,
            $this->createMockPolicy(['field' => 'TYPE_SPECIFIC']),
        );

        $result = $pipeline->redact(['field' => 'original'], EventType::HttpRequest);

        // Type policy runs after global, so type-specific value should win
        self::assertSame('TYPE_SPECIFIC', $result['field']);
    }

    #[Test]
    public function redactWithMultipleTypePolicies(): void
    {
        $pipeline = new RedactionPipeline();
        $pipeline->addTypePolicy(
            EventType::DatabaseQuery,
            $this->createMockPolicy(['query' => 'REDACTED_QUERY']),
        );
        $pipeline->addTypePolicy(
            EventType::DatabaseQuery,
            $this->createMockPolicy(['params' => 'REDACTED_PARAMS']),
        );

        $result = $pipeline->redact(
            ['query' => 'SELECT * FROM users', 'params' => ['id' => 1]],
            EventType::DatabaseQuery,
        );

        self::assertSame('REDACTED_QUERY', $result['query']);
        self::assertSame('REDACTED_PARAMS', $result['params']);
    }

    #[Test]
    public function redactWithNoPoliciesReturnsOriginal(): void
    {
        $pipeline = new RedactionPipeline();

        $result = $pipeline->redact(
            ['field1' => 'value1', 'field2' => 'value2'],
            EventType::LogEntry,
        );

        self::assertSame(['field1' => 'value1', 'field2' => 'value2'], $result);
    }

    #[Test]
    public function redactWithNestedArrays(): void
    {
        $pipeline = new RedactionPipeline();
        $pipeline->addGlobalPolicy(new DefaultRedactionPolicy());

        $result = $pipeline->redact([
            'user' => [
                'name' => 'Alice',
                'password' => 'secret123',
            ],
            'status' => 'active',
        ], EventType::LogEntry);

        /** @var array<string, mixed> $user */
        $user = $result['user'];
        self::assertSame('Alice', $user['name']);
        self::assertSame('[REDACTED]', $user['password']);
        self::assertSame('active', $result['status']);
    }

    #[Test]
    public function withDefaultsCreatesDefaultPipeline(): void
    {
        $pipeline = RedactionPipeline::withDefaults();

        $result = $pipeline->redact([
            'password' => 'secret',
            'token' => 'abc123',
            'name' => 'test',
        ], EventType::LogEntry);

        self::assertSame('[REDACTED]', $result['password']);
        self::assertSame('[REDACTED]', $result['token']);
        self::assertSame('test', $result['name']);
    }

    #[Test]
    public function redactHandlesEmptyPayload(): void
    {
        $pipeline = RedactionPipeline::withDefaults();

        $result = $pipeline->redact([], EventType::LogEntry);

        self::assertSame([], $result);
    }

    #[Test]
    public function redactReplacesValuesWithRedactedMarker(): void
    {
        $pipeline = RedactionPipeline::withDefaults();

        $result = $pipeline->redact([
            'api_key' => 'sk_live_12345',
            'secret' => 'very_secret_value',
        ], EventType::HttpRequest);

        self::assertSame('[REDACTED]', $result['api_key']);
        self::assertSame('[REDACTED]', $result['secret']);
    }

    #[Test]
    public function redactWithDifferentEventTypes(): void
    {
        $pipeline = new RedactionPipeline();
        $pipeline->addTypePolicy(
            EventType::HttpRequest,
            $this->createMockPolicy(['headers' => 'REQUEST_REDACTED']),
        );
        $pipeline->addTypePolicy(
            EventType::HttpResponse,
            $this->createMockPolicy(['headers' => 'RESPONSE_REDACTED']),
        );

        $requestResult = $pipeline->redact(['headers' => 'original'], EventType::HttpRequest);
        $responseResult = $pipeline->redact(['headers' => 'original'], EventType::HttpResponse);

        self::assertSame('REQUEST_REDACTED', $requestResult['headers']);
        self::assertSame('RESPONSE_REDACTED', $responseResult['headers']);
    }

    #[Test]
    public function redactChainsPoliciesCorrectly(): void
    {
        $pipeline = new RedactionPipeline();

        // First policy marks field as processed
        $pipeline->addGlobalPolicy(new class implements RedactionPolicyInterface {
            public function redact(array $payload): array
            {
                $payload['processed'] = true;

                return $payload;
            }
        });

        // Second policy checks for processed flag
        $pipeline->addGlobalPolicy(new class implements RedactionPolicyInterface {
            public function redact(array $payload): array
            {
                if (isset($payload['processed']) && $payload['processed'] === true) {
                    $payload['verified'] = true;
                }

                return $payload;
            }
        });

        $result = $pipeline->redact(['data' => 'test'], EventType::LogEntry);

        self::assertTrue($result['processed']);
        self::assertTrue($result['verified']);
    }

    /**
     * Create a mock policy that replaces specific fields.
     *
     * @param array<string, mixed> $replacements
     */
    private function createMockPolicy(array $replacements): RedactionPolicyInterface
    {
        return new class ($replacements) implements RedactionPolicyInterface {
            /**
             * @param array<string, mixed> $replacements
             */
            public function __construct(private readonly array $replacements) {}

            public function redact(array $payload): array
            {
                foreach ($this->replacements as $key => $value) {
                    if (isset($payload[$key])) {
                        $payload[$key] = $value;
                    }
                }

                return $payload;
            }
        };
    }
}
