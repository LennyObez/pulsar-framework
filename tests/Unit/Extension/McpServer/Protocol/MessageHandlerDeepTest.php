<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\McpServer\Protocol;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Extension\McpServer\Config\McpConfig;
use Pulsar\Extension\McpServer\Config\McpSecurityConfig;
use Pulsar\Extension\McpServer\Config\McpToolsConfig;
use Pulsar\Extension\McpServer\Contracts\McpRedactionPipelineInterface;
use Pulsar\Extension\McpServer\Contracts\McpToolRegistryInterface;
use Pulsar\Extension\McpServer\Contracts\ToolPermissionCheckerInterface;
use Pulsar\Extension\McpServer\Domain\ToolResult;
use Pulsar\Extension\McpServer\Exception\McpException;
use Pulsar\Extension\McpServer\Internal\Protocol\JsonRpcRequest;
use Pulsar\Extension\McpServer\Internal\Protocol\MessageHandler;
use Pulsar\Http\RateLimit\RateLimiterInterface;
use Pulsar\Http\RateLimit\RateLimitResult;
use Pulsar\Observability\ErrorTracking\SensitiveDataScrubber;
use RuntimeException;

use function assert;
use function is_array;
use function is_string;

#[CoversClass(MessageHandler::class)]
final class MessageHandlerDeepTest extends TestCase
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
     * Decode a JSON-RPC response string into an associative array.
     *
     * @return array<string, mixed>
     */
    private function decodeResponse(string $response): array
    {
        /** @var array<string, mixed> */
        return json_decode($response, true, 512, JSON_THROW_ON_ERROR);
    }

    #[Test]
    public function handleUnknownMethodReturnsError(): void
    {
        $handler = $this->createHandler();

        $request = new JsonRpcRequest(id: 1, method: 'unknown/method', params: []);
        $response = $handler->handle($request);

        self::assertNotNull($response);
        $decoded = $this->decodeResponse($response);
        self::assertArrayHasKey('error', $decoded);

        $error = $decoded['error'];
        assert(is_array($error));
        self::assertSame(-32601, $error['code']);
        $errorMessage = $error['message'];
        assert(is_string($errorMessage));
        self::assertStringContainsString('unknown/method', $errorMessage);
    }

    #[Test]
    public function handleInitializeWithMissingProtocolVersion(): void
    {
        $handler = $this->createHandler();

        $request = new JsonRpcRequest(id: 1, method: 'initialize', params: []);
        $response = $handler->handle($request);

        self::assertNotNull($response);
        $decoded = $this->decodeResponse($response);
        self::assertArrayHasKey('error', $decoded);

        $error = $decoded['error'];
        assert(is_array($error));
        self::assertSame(-32602, $error['code']);
    }

    #[Test]
    public function handleInitializeWithEmptyProtocolVersion(): void
    {
        $handler = $this->createHandler();

        $request = new JsonRpcRequest(id: 1, method: 'initialize', params: ['protocolVersion' => '']);
        $response = $handler->handle($request);

        self::assertNotNull($response);
        $decoded = $this->decodeResponse($response);
        self::assertArrayHasKey('error', $decoded);

        $error = $decoded['error'];
        assert(is_array($error));
        self::assertSame(-32602, $error['code']);
    }

    #[Test]
    public function handleInitializeWithNonStringProtocolVersion(): void
    {
        $handler = $this->createHandler();

        $request = new JsonRpcRequest(id: 1, method: 'initialize', params: ['protocolVersion' => 123]);
        $response = $handler->handle($request);

        self::assertNotNull($response);
        $decoded = $this->decodeResponse($response);
        self::assertArrayHasKey('error', $decoded);
    }

    #[Test]
    public function handleToolsCallWithMissingToolName(): void
    {
        $handler = $this->createHandler();

        $request = new JsonRpcRequest(id: 1, method: 'tools/call', params: []);
        $response = $handler->handle($request);

        self::assertNotNull($response);
        $decoded = $this->decodeResponse($response);
        self::assertArrayHasKey('error', $decoded);

        $error = $decoded['error'];
        assert(is_array($error));
        self::assertSame(-32602, $error['code']);
    }

    #[Test]
    public function handleToolsCallWithEmptyToolName(): void
    {
        $handler = $this->createHandler();

        $request = new JsonRpcRequest(id: 1, method: 'tools/call', params: ['name' => '']);
        $response = $handler->handle($request);

        self::assertNotNull($response);
        $decoded = $this->decodeResponse($response);
        self::assertArrayHasKey('error', $decoded);
    }

    #[Test]
    public function handleToolsCallRateLimited(): void
    {
        $rateLimiter = $this->createStub(RateLimiterInterface::class);
        $rateLimiter->method('hit')->willReturn(
            new RateLimitResult(allowed: false, limit: 10, remaining: 0, retryAfter: 30),
        );

        $handler = $this->createHandler(rateLimiter: $rateLimiter);

        $request = new JsonRpcRequest(id: 1, method: 'tools/call', params: [
            'name' => 'test_tool',
            'arguments' => [],
        ]);

        $response = $handler->handle($request);

        self::assertNotNull($response);
        $decoded = $this->decodeResponse($response);

        $result = $decoded['result'];
        assert(is_array($result));
        self::assertTrue($result['isError']);

        $content = $result['content'];
        assert(is_array($content));
        $firstItem = $content[0];
        assert(is_array($firstItem));
        $text = $firstItem['text'];
        assert(is_string($text));
        self::assertStringContainsString('Rate limited', $text);
        self::assertStringContainsString('30', $text);
    }

    #[Test]
    public function handleToolsCallMcpExceptionCaught(): void
    {
        $registry = $this->createStub(McpToolRegistryInterface::class);
        $registry->method('call')->willThrowException(McpException::toolNotFound('missing'));

        $handler = $this->createHandler(registry: $registry);

        $request = new JsonRpcRequest(id: 1, method: 'tools/call', params: [
            'name' => 'missing',
            'arguments' => [],
        ]);

        $response = $handler->handle($request);

        self::assertNotNull($response);
        $decoded = $this->decodeResponse($response);

        $result = $decoded['result'];
        assert(is_array($result));
        self::assertTrue($result['isError']);
    }

    #[Test]
    public function handleToolsCallGenericExceptionCaught(): void
    {
        $registry = $this->createStub(McpToolRegistryInterface::class);
        $registry->method('call')->willThrowException(new RuntimeException('unexpected'));

        $handler = $this->createHandler(registry: $registry);

        $request = new JsonRpcRequest(id: 1, method: 'tools/call', params: [
            'name' => 'crash_tool',
            'arguments' => [],
        ]);

        $response = $handler->handle($request);

        self::assertNotNull($response);
        $decoded = $this->decodeResponse($response);

        $result = $decoded['result'];
        assert(is_array($result));
        self::assertTrue($result['isError']);

        $content = $result['content'];
        assert(is_array($content));
        $firstItem = $content[0];
        assert(is_array($firstItem));
        $text = $firstItem['text'];
        assert(is_string($text));
        self::assertStringContainsString('Internal tool execution error', $text);
    }

    #[Test]
    public function handleToolsCallWithNullAuditLogger(): void
    {
        $registry = $this->createStub(McpToolRegistryInterface::class);
        $registry->method('call')->willReturn(ToolResult::success([], 'ok'));

        $handler = $this->createHandler(registry: $registry, auditLogger: null);

        $request = new JsonRpcRequest(id: 1, method: 'tools/call', params: [
            'name' => 'some_tool',
            'arguments' => [],
        ]);

        $response = $handler->handle($request);

        self::assertNotNull($response);
        $decoded = $this->decodeResponse($response);

        $result = $decoded['result'];
        assert(is_array($result));
        self::assertFalse($result['isError']);
    }

    #[Test]
    public function handleToolsCallWithNullRateLimiter(): void
    {
        $registry = $this->createStub(McpToolRegistryInterface::class);
        $registry->method('call')->willReturn(ToolResult::success([], 'ok'));

        $handler = $this->createHandler(registry: $registry, rateLimiter: null);

        $request = new JsonRpcRequest(id: 1, method: 'tools/call', params: [
            'name' => 'some_tool',
            'arguments' => [],
        ]);

        $response = $handler->handle($request);

        self::assertNotNull($response);
        $decoded = $this->decodeResponse($response);

        $result = $decoded['result'];
        assert(is_array($result));
        self::assertFalse($result['isError']);
    }

    #[Test]
    public function handleToolsCallWithMetaInResult(): void
    {
        $registry = $this->createStub(McpToolRegistryInterface::class);
        $registry->method('call')->willReturn(new ToolResult(
            structuredContent: [],
            textContent: 'done',
            isError: false,
            meta: ['duration_ms' => 42],
        ));

        $handler = $this->createHandler(registry: $registry);

        $request = new JsonRpcRequest(id: 1, method: 'tools/call', params: [
            'name' => 'some_tool',
            'arguments' => [],
        ]);

        $response = $handler->handle($request);

        self::assertNotNull($response);
        $decoded = $this->decodeResponse($response);

        $result = $decoded['result'];
        assert(is_array($result));
        self::assertSame(['duration_ms' => 42], $result['_meta']);
    }

    #[Test]
    public function handleToolsCallErrorResultLogsFailure(): void
    {
        $registry = $this->createStub(McpToolRegistryInterface::class);
        $registry->method('call')->willReturn(ToolResult::error('something broke'));

        $handler = $this->createHandler(registry: $registry);

        $request = new JsonRpcRequest(id: 1, method: 'tools/call', params: [
            'name' => 'broken_tool',
            'arguments' => [],
        ]);

        $response = $handler->handle($request);

        self::assertNotNull($response);
        $decoded = $this->decodeResponse($response);

        $result = $decoded['result'];
        assert(is_array($result));
        self::assertTrue($result['isError']);

        $content = $result['content'];
        assert(is_array($content));
        $firstItem = $content[0];
        assert(is_array($firstItem));
        self::assertSame('something broke', $firstItem['text']);
    }

    #[Test]
    public function handleCancelledNotificationReturnsNull(): void
    {
        $handler = $this->createHandler();

        $request = new JsonRpcRequest(id: null, method: 'notifications/cancelled', params: [
            'requestId' => 'req-42',
        ]);

        self::assertNull($handler->handle($request));
    }

    #[Test]
    public function handleToolsListWithCursor(): void
    {
        $registry = $this->createStub(McpToolRegistryInterface::class);
        $registry->method('list')->willReturn([]);

        $handler = $this->createHandler(registry: $registry);

        $request = new JsonRpcRequest(id: 1, method: 'tools/list', params: ['cursor' => 'abc']);
        $response = $handler->handle($request);

        self::assertNotNull($response);
        $decoded = $this->decodeResponse($response);

        $result = $decoded['result'];
        assert(is_array($result));
        self::assertSame('abc', $result['nextCursor']);
    }

    #[Test]
    public function handleInitializeNegotiatesLatestKnownVersion(): void
    {
        $handler = $this->createHandler();

        $request = new JsonRpcRequest(id: 1, method: 'initialize', params: [
            'protocolVersion' => '2025-03-26',
        ]);
        $response = $handler->handle($request);

        self::assertNotNull($response);
        $decoded = $this->decodeResponse($response);

        $result = $decoded['result'];
        assert(is_array($result));
        self::assertSame('2025-03-26', $result['protocolVersion']);
    }

    #[Test]
    public function handleInitializeNegotiatesLatestForFutureVersion(): void
    {
        $handler = $this->createHandler();

        $request = new JsonRpcRequest(id: 1, method: 'initialize', params: [
            'protocolVersion' => '2030-01-01',
        ]);
        $response = $handler->handle($request);

        self::assertNotNull($response);
        $decoded = $this->decodeResponse($response);

        $result = $decoded['result'];
        assert(is_array($result));
        self::assertSame('2025-11-25', $result['protocolVersion']);
    }
}
