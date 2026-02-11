<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\McpServer\Protocol;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Extension\McpServer\Config\McpConfig;
use Pulsar\Extension\McpServer\Config\McpSecurityConfig;
use Pulsar\Extension\McpServer\Config\McpToolsConfig;
use Pulsar\Extension\McpServer\Contracts\McpRedactionPipelineInterface;
use Pulsar\Extension\McpServer\Contracts\McpToolRegistryInterface;
use Pulsar\Extension\McpServer\Contracts\ToolPermissionCheckerInterface;
use Pulsar\Extension\McpServer\Domain\ToolCategory;
use Pulsar\Extension\McpServer\Domain\ToolDefinition;
use Pulsar\Extension\McpServer\Domain\ToolResult;
use Pulsar\Extension\McpServer\Exception\McpException;
use Pulsar\Extension\McpServer\Exception\McpSecurityException;
use Pulsar\Extension\McpServer\Internal\Protocol\JsonRpcCodec;
use Pulsar\Extension\McpServer\Internal\Protocol\JsonRpcRequest;
use Pulsar\Extension\McpServer\Internal\Protocol\MessageHandler;
use Pulsar\Http\RateLimit\RateLimiterInterface;
use Pulsar\Http\RateLimit\RateLimitResult;
use Pulsar\Observability\ErrorTracking\SensitiveDataScrubber;
use Pulsar\Security\Audit\AuditEvent;
use Pulsar\Security\Audit\AuditOutcome;
use RuntimeException;

use function assert;
use function is_array;
use function is_string;
use function json_decode;

use const JSON_THROW_ON_ERROR;

/**
 * Comprehensive tests for MessageHandler covering sensitive data scrubbing,
 * redaction pipeline integration, audit logging verification, protocol
 * version negotiation, and all edge cases in the tool call pipeline.
 */
#[CoversClass(MessageHandler::class)]
#[CoversClass(JsonRpcCodec::class)]
final class MessageHandlerComprehensiveTest extends TestCase
{
    private McpConfig $config;
    private SensitiveDataScrubber $scrubber;

    protected function setUp(): void
    {
        $this->scrubber = new SensitiveDataScrubber();
        $this->config = new McpConfig(
            enabled: true,
            clientId: 'test-client',
            projectRoot: '/tmp',
            tools: McpToolsConfig::fromArray([]),
            security: McpSecurityConfig::fromArray([]),
        );
    }

    private function createHandler(
        ?McpToolRegistryInterface $registry = null,
        ?ToolPermissionCheckerInterface $permissionChecker = null,
        ?McpRedactionPipelineInterface $redactionPipeline = null,
        ?AuditLoggerInterface $auditLogger = null,
        ?RateLimiterInterface $rateLimiter = null,
    ): MessageHandler {
        $defaultRedaction = $this->createStub(McpRedactionPipelineInterface::class);
        $defaultRedaction->method('redact')->willReturnArgument(0);

        return new MessageHandler(
            registry: $registry ?? $this->createStub(McpToolRegistryInterface::class),
            permissionChecker: $permissionChecker ?? $this->createStub(ToolPermissionCheckerInterface::class),
            redactionPipeline: $redactionPipeline ?? $defaultRedaction,
            auditLogger: $auditLogger,
            rateLimiter: $rateLimiter,
            config: $this->config,
            scrubber: $this->scrubber,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeResponse(string $response): array
    {
        /** @var array<string, mixed> */
        return json_decode($response, true, 512, JSON_THROW_ON_ERROR);
    }

    // --- Sensitive data scrubbing ---

    #[Test]
    public function scrubberRedactsSensitiveFieldsInToolOutput(): void
    {
        $toolResult = ToolResult::success(
            structuredContent: [
                'user' => 'john',
                'password' => 'secret123',
                'api_key' => 'key-abc',
                'data' => 'safe',
            ],
            textContent: 'Result with sensitive data',
        );

        $registry = $this->createStub(McpToolRegistryInterface::class);
        $registry->method('call')->willReturn($toolResult);

        $handler = $this->createHandler(registry: $registry);

        $request = new JsonRpcRequest(id: 1, method: 'tools/call', params: [
            'name' => 'read_config',
            'arguments' => [],
        ]);

        $response = $handler->handle($request);
        self::assertNotNull($response);

        $decoded = $this->decodeResponse($response);
        $result = $decoded['result'];
        assert(is_array($result));

        // structuredContent should have sensitive fields scrubbed
        $structured = $result['structuredContent'];
        assert(is_array($structured));

        self::assertSame('[REDACTED]', $structured['password']);
        self::assertSame('[REDACTED]', $structured['api_key']);
        self::assertSame('john', $structured['user']);
        self::assertSame('safe', $structured['data']);
    }

    #[Test]
    public function scrubberDoesNotModifyWhenNoSensitiveFields(): void
    {
        $toolResult = ToolResult::success(
            structuredContent: ['name' => 'alice', 'role' => 'admin'],
            textContent: 'Clean data',
        );

        $registry = $this->createStub(McpToolRegistryInterface::class);
        $registry->method('call')->willReturn($toolResult);

        $handler = $this->createHandler(registry: $registry);

        $request = new JsonRpcRequest(id: 1, method: 'tools/call', params: [
            'name' => 'tool',
            'arguments' => [],
        ]);

        $response = $handler->handle($request);
        self::assertNotNull($response);

        $decoded = $this->decodeResponse($response);
        $result = $decoded['result'];
        assert(is_array($result));
        $structured = $result['structuredContent'];
        assert(is_array($structured));

        self::assertSame('alice', $structured['name']);
        self::assertSame('admin', $structured['role']);
    }

    // --- Redaction pipeline integration ---

    #[Test]
    public function redactionPipelineTransformsResult(): void
    {
        $originalResult = ToolResult::success(
            structuredContent: ['ssn' => '123-45-6789'],
            textContent: 'SSN: 123-45-6789',
        );

        $redactedResult = ToolResult::success(
            structuredContent: ['ssn' => '***-**-****'],
            textContent: 'SSN: ***-**-****',
        );

        $registry = $this->createStub(McpToolRegistryInterface::class);
        $registry->method('call')->willReturn($originalResult);

        $redactionPipeline = $this->createStub(McpRedactionPipelineInterface::class);
        $redactionPipeline->method('redact')->willReturn($redactedResult);

        $handler = $this->createHandler(
            registry: $registry,
            redactionPipeline: $redactionPipeline,
        );

        $request = new JsonRpcRequest(id: 1, method: 'tools/call', params: [
            'name' => 'read_records',
            'arguments' => [],
        ]);

        $response = $handler->handle($request);
        self::assertNotNull($response);

        $decoded = $this->decodeResponse($response);
        $result = $decoded['result'];
        assert(is_array($result));
        $content = $result['content'];
        assert(is_array($content));

        assert(is_array($content[0]));
        self::assertSame('SSN: ***-**-****', $content[0]['text']);
    }

    // --- Audit logging verification ---

    #[Test]
    public function auditLoggerRecordsSuccessfulToolCall(): void
    {
        $toolResult = ToolResult::success([], 'ok');

        $registry = $this->createStub(McpToolRegistryInterface::class);
        $registry->method('call')->willReturn($toolResult);

        $auditLogger = $this->createMock(AuditLoggerInterface::class);
        $auditLogger->expects(self::once())
            ->method('log')
            ->with(
                AuditEvent::DataAccess,
                AuditOutcome::Success,
                'mcp-client:test-client',
                'mcp:tools/call:my_tool',
                'my_tool',
                self::callback(static fn(array $meta): bool => $meta['detail'] === 'OK'),
            );

        $handler = $this->createHandler(
            registry: $registry,
            auditLogger: $auditLogger,
        );

        $request = new JsonRpcRequest(id: 1, method: 'tools/call', params: [
            'name' => 'my_tool',
            'arguments' => [],
        ]);

        $handler->handle($request);
    }

    #[Test]
    public function auditLoggerRecordsDeniedToolCall(): void
    {
        $permissionChecker = $this->createStub(ToolPermissionCheckerInterface::class);
        $permissionChecker->method('assertAllowed')
            ->willThrowException(McpSecurityException::toolNotAllowed('forbidden_tool'));

        $auditLogger = $this->createMock(AuditLoggerInterface::class);
        $auditLogger->expects(self::once())
            ->method('log')
            ->with(
                AuditEvent::DataAccess,
                AuditOutcome::Denied,
                'mcp-client:test-client',
                'mcp:tools/call:forbidden_tool',
                'forbidden_tool',
                self::anything(),
            );

        $handler = $this->createHandler(
            permissionChecker: $permissionChecker,
            auditLogger: $auditLogger,
        );

        $request = new JsonRpcRequest(id: 1, method: 'tools/call', params: [
            'name' => 'forbidden_tool',
            'arguments' => [],
        ]);

        $handler->handle($request);
    }

    #[Test]
    public function auditLoggerRecordsRateLimitedToolCall(): void
    {
        $rateLimiter = $this->createStub(RateLimiterInterface::class);
        $rateLimiter->method('hit')->willReturn(
            new RateLimitResult(allowed: false, limit: 10, remaining: 0, retryAfter: 60),
        );

        $auditLogger = $this->createMock(AuditLoggerInterface::class);
        $auditLogger->expects(self::once())
            ->method('log')
            ->with(
                AuditEvent::DataAccess,
                AuditOutcome::Denied,
                'mcp-client:test-client',
                self::stringContains('rate_tool'),
                'rate_tool',
                self::callback(static fn(array $meta): bool => $meta['detail'] === 'Rate limited'),
            );

        $handler = $this->createHandler(
            rateLimiter: $rateLimiter,
            auditLogger: $auditLogger,
        );

        $request = new JsonRpcRequest(id: 1, method: 'tools/call', params: [
            'name' => 'rate_tool',
            'arguments' => [],
        ]);

        $handler->handle($request);
    }

    #[Test]
    public function auditLoggerRecordsErrorOnMcpException(): void
    {
        $registry = $this->createStub(McpToolRegistryInterface::class);
        $registry->method('call')->willThrowException(McpException::toolNotFound('missing'));

        $auditLogger = $this->createMock(AuditLoggerInterface::class);
        $auditLogger->expects(self::once())
            ->method('log')
            ->with(
                AuditEvent::DataAccess,
                AuditOutcome::Error,
                self::anything(),
                self::anything(),
                'missing',
                self::anything(),
            );

        $handler = $this->createHandler(
            registry: $registry,
            auditLogger: $auditLogger,
        );

        $request = new JsonRpcRequest(id: 1, method: 'tools/call', params: [
            'name' => 'missing',
            'arguments' => [],
        ]);

        $handler->handle($request);
    }

    #[Test]
    public function auditLoggerRecordsErrorOnGenericThrowable(): void
    {
        $registry = $this->createStub(McpToolRegistryInterface::class);
        $registry->method('call')->willThrowException(new RuntimeException('kaboom'));

        $auditLogger = $this->createMock(AuditLoggerInterface::class);
        $auditLogger->expects(self::once())
            ->method('log')
            ->with(
                AuditEvent::DataAccess,
                AuditOutcome::Error,
                self::anything(),
                self::anything(),
                'crash_tool',
                self::anything(),
            );

        $handler = $this->createHandler(
            registry: $registry,
            auditLogger: $auditLogger,
        );

        $request = new JsonRpcRequest(id: 1, method: 'tools/call', params: [
            'name' => 'crash_tool',
            'arguments' => [],
        ]);

        $handler->handle($request);
    }

    // --- Protocol version negotiation ---

    /**
     * @return array<string, array{string, string}>
     */
    public static function protocolVersionNegotiationProvider(): array
    {
        return [
            'known oldest version' => ['2024-11-05', '2024-11-05'],
            'known middle version' => ['2025-03-26', '2025-03-26'],
            'known newest version' => ['2025-11-25', '2025-11-25'],
            'unknown future date' => ['2030-12-31', '2025-11-25'],
            'unknown past date' => ['2020-01-01', '2025-11-25'],
        ];
    }

    #[Test]
    #[DataProvider('protocolVersionNegotiationProvider')]
    public function initializeNegotiatesCorrectVersion(string $clientVersion, string $expected): void
    {
        $handler = $this->createHandler();

        $request = new JsonRpcRequest(id: 1, method: 'initialize', params: [
            'protocolVersion' => $clientVersion,
        ]);

        $response = $handler->handle($request);
        self::assertNotNull($response);

        $decoded = $this->decodeResponse($response);
        $result = $decoded['result'];
        assert(is_array($result));
        self::assertSame($expected, $result['protocolVersion']);
    }

    // --- Initialize response structure ---

    #[Test]
    public function initializeResponseContainsServerInfo(): void
    {
        $handler = $this->createHandler();

        $request = new JsonRpcRequest(id: 42, method: 'initialize', params: [
            'protocolVersion' => '2024-11-05',
        ]);

        $response = $handler->handle($request);
        self::assertNotNull($response);

        $decoded = $this->decodeResponse($response);
        self::assertSame(42, $decoded['id']);

        $result = $decoded['result'];
        assert(is_array($result));

        $serverInfo = $result['serverInfo'];
        assert(is_array($serverInfo));
        self::assertSame('pulsar-mcp', $serverInfo['name']);
        self::assertArrayHasKey('version', $serverInfo);

        $capabilities = $result['capabilities'];
        assert(is_array($capabilities));
        $tools = $capabilities['tools'];
        assert(is_array($tools));
        self::assertFalse($tools['listChanged']);
    }

    // --- Tool result encoding ---

    #[Test]
    public function toolResultWithEmptyStructuredContentOmitsField(): void
    {
        $toolResult = ToolResult::success([], 'done');

        $registry = $this->createStub(McpToolRegistryInterface::class);
        $registry->method('call')->willReturn($toolResult);

        $handler = $this->createHandler(registry: $registry);

        $request = new JsonRpcRequest(id: 1, method: 'tools/call', params: [
            'name' => 'tool',
            'arguments' => [],
        ]);

        $response = $handler->handle($request);
        self::assertNotNull($response);

        $decoded = $this->decodeResponse($response);
        $result = $decoded['result'];
        assert(is_array($result));

        // When structuredContent is empty, it should NOT be in the response
        self::assertArrayNotHasKey('structuredContent', $result);
    }

    #[Test]
    public function toolResultWithEmptyMetaOmitsMetaField(): void
    {
        $toolResult = ToolResult::success(['key' => 'val'], 'done');

        $registry = $this->createStub(McpToolRegistryInterface::class);
        $registry->method('call')->willReturn($toolResult);

        $handler = $this->createHandler(registry: $registry);

        $request = new JsonRpcRequest(id: 1, method: 'tools/call', params: [
            'name' => 'tool',
            'arguments' => [],
        ]);

        $response = $handler->handle($request);
        self::assertNotNull($response);

        $decoded = $this->decodeResponse($response);
        $result = $decoded['result'];
        assert(is_array($result));

        self::assertArrayNotHasKey('_meta', $result);
    }

    #[Test]
    public function toolResultWithNonEmptyMetaIncludesMetaField(): void
    {
        $toolResult = new ToolResult(
            structuredContent: [],
            textContent: 'truncated',
            isError: false,
            meta: ['truncated' => true, 'original_bytes' => 1000],
        );

        $registry = $this->createStub(McpToolRegistryInterface::class);
        $registry->method('call')->willReturn($toolResult);

        $handler = $this->createHandler(registry: $registry);

        $request = new JsonRpcRequest(id: 1, method: 'tools/call', params: [
            'name' => 'tool',
            'arguments' => [],
        ]);

        $response = $handler->handle($request);
        self::assertNotNull($response);

        $decoded = $this->decodeResponse($response);
        $result = $decoded['result'];
        assert(is_array($result));

        self::assertSame(['truncated' => true, 'original_bytes' => 1000], $result['_meta']);
    }

    // --- Error result from tool execution ---

    #[Test]
    public function toolResultErrorHasCorrectShape(): void
    {
        $toolResult = ToolResult::error('Something went wrong');

        $registry = $this->createStub(McpToolRegistryInterface::class);
        $registry->method('call')->willReturn($toolResult);

        $handler = $this->createHandler(registry: $registry);

        $request = new JsonRpcRequest(id: 99, method: 'tools/call', params: [
            'name' => 'broken',
            'arguments' => [],
        ]);

        $response = $handler->handle($request);
        self::assertNotNull($response);

        $decoded = $this->decodeResponse($response);
        self::assertSame(99, $decoded['id']);

        $result = $decoded['result'];
        assert(is_array($result));
        self::assertTrue($result['isError']);

        $content = $result['content'];
        assert(is_array($content));
        assert(is_array($content[0]));
        self::assertSame('text', $content[0]['type']);
        self::assertSame('Something went wrong', $content[0]['text']);
    }

    // --- Tools/call with non-string name ---

    #[Test]
    public function toolsCallWithNonStringNameReturnsError(): void
    {
        $handler = $this->createHandler();

        $request = new JsonRpcRequest(id: 1, method: 'tools/call', params: [
            'name' => 123,
        ]);

        $response = $handler->handle($request);
        self::assertNotNull($response);

        $decoded = $this->decodeResponse($response);
        self::assertArrayHasKey('error', $decoded);
    }

    // --- Generic throwable hides internal details ---

    #[Test]
    public function genericExceptionHidesInternalMessage(): void
    {
        $registry = $this->createStub(McpToolRegistryInterface::class);
        $registry->method('call')->willThrowException(
            new RuntimeException('SQL injection detected at line 42'),
        );

        $handler = $this->createHandler(registry: $registry);

        $request = new JsonRpcRequest(id: 1, method: 'tools/call', params: [
            'name' => 'db_query',
            'arguments' => [],
        ]);

        $response = $handler->handle($request);
        self::assertNotNull($response);

        $decoded = $this->decodeResponse($response);
        $result = $decoded['result'];
        assert(is_array($result));
        $content = $result['content'];
        assert(is_array($content));
        assert(is_array($content[0]));
        $text = $content[0]['text'];
        assert(is_string($text));

        // The internal error message should NOT be exposed
        self::assertStringNotContainsString('SQL injection', $text);
        self::assertSame('Internal tool execution error', $text);
    }

    // --- McpException exposes its message ---

    #[Test]
    public function mcpExceptionExposesMessageToClient(): void
    {
        $registry = $this->createStub(McpToolRegistryInterface::class);
        $registry->method('call')->willThrowException(
            McpException::executionFailed('my_tool', 'Invalid input format'),
        );

        $handler = $this->createHandler(registry: $registry);

        $request = new JsonRpcRequest(id: 1, method: 'tools/call', params: [
            'name' => 'my_tool',
            'arguments' => [],
        ]);

        $response = $handler->handle($request);
        self::assertNotNull($response);

        $decoded = $this->decodeResponse($response);
        $result = $decoded['result'];
        assert(is_array($result));
        $content = $result['content'];
        assert(is_array($content));

        assert(is_array($content[0]));
        $text = $content[0]['text'];
        assert(is_string($text));
        self::assertStringContainsString('Invalid input format', $text);
    }

    // --- Tools/list with multiple tools ---

    #[Test]
    public function toolsListReturnsAllToolDefinitions(): void
    {
        $registry = $this->createStub(McpToolRegistryInterface::class);
        $registry->method('list')->willReturn([
            new ToolDefinition('read_routes', 'Read routes', ['type' => 'object'], ['type' => 'object'], ToolCategory::Read),
            new ToolDefinition('run_tests', 'Run tests', ['type' => 'object'], ['type' => 'object'], ToolCategory::Action),
        ]);

        $handler = $this->createHandler(registry: $registry);

        $request = new JsonRpcRequest(id: 1, method: 'tools/list', params: []);

        $response = $handler->handle($request);
        self::assertNotNull($response);

        $decoded = $this->decodeResponse($response);
        $result = $decoded['result'];
        assert(is_array($result));

        $tools = $result['tools'];
        assert(is_array($tools));
        self::assertCount(2, $tools);

        $tool0 = $tools[0];
        assert(is_array($tool0));
        $tool1 = $tools[1];
        assert(is_array($tool1));
        self::assertSame('read_routes', $tool0['name']);
        self::assertSame('run_tests', $tool1['name']);
        self::assertArrayHasKey('inputSchema', $tool0);
        self::assertArrayHasKey('outputSchema', $tool0);
    }

    #[Test]
    public function toolsListWithNoCursorReturnsNullNextCursor(): void
    {
        $registry = $this->createStub(McpToolRegistryInterface::class);
        $registry->method('list')->willReturn([]);

        $handler = $this->createHandler(registry: $registry);

        $request = new JsonRpcRequest(id: 1, method: 'tools/list', params: []);

        $response = $handler->handle($request);
        self::assertNotNull($response);

        $decoded = $this->decodeResponse($response);
        $result = $decoded['result'];
        assert(is_array($result));
        self::assertNull($result['nextCursor']);
    }

    // --- String ID support ---

    #[Test]
    public function handlerAcceptsStringId(): void
    {
        $handler = $this->createHandler();

        $request = new JsonRpcRequest(id: 'string-id-123', method: 'ping', params: []);

        $response = $handler->handle($request);
        self::assertNotNull($response);

        $decoded = $this->decodeResponse($response);
        self::assertSame('string-id-123', $decoded['id']);
    }

    // --- Unknown notification method ---

    #[Test]
    public function unknownNotificationReturnsNull(): void
    {
        $handler = $this->createHandler();

        $request = new JsonRpcRequest(id: null, method: 'notifications/unknown', params: []);

        self::assertNull($handler->handle($request));
    }

    // --- Arguments default to empty ---

    #[Test]
    public function toolsCallWithMissingArgumentsDefaultsToEmpty(): void
    {
        $registry = $this->createMock(McpToolRegistryInterface::class);
        $registry->expects(self::once())
            ->method('call')
            ->with('my_tool', [])
            ->willReturn(ToolResult::success([], 'ok'));

        $handler = $this->createHandler(registry: $registry);

        $request = new JsonRpcRequest(id: 1, method: 'tools/call', params: [
            'name' => 'my_tool',
            // no 'arguments' key
        ]);

        $handler->handle($request);
    }
}
