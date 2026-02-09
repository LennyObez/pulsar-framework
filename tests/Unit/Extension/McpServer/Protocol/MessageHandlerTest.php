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
use Pulsar\Extension\McpServer\Contracts\McpAccessGateInterface;
use Pulsar\Extension\McpServer\Contracts\McpRedactionPipelineInterface;
use Pulsar\Extension\McpServer\Contracts\McpToolRegistryInterface;
use Pulsar\Extension\McpServer\Contracts\ToolPermissionCheckerInterface;
use Pulsar\Extension\McpServer\Domain\ToolCategory;
use Pulsar\Extension\McpServer\Domain\ToolDefinition;
use Pulsar\Extension\McpServer\Domain\ToolResult;
use Pulsar\Extension\McpServer\Exception\McpSecurityException;
use Pulsar\Extension\McpServer\Internal\Protocol\JsonRpcCodec;
use Pulsar\Extension\McpServer\Internal\Protocol\JsonRpcRequest;
use Pulsar\Extension\McpServer\Internal\Protocol\MessageHandler;
use Pulsar\Http\RateLimit\RateLimiterInterface;
use Pulsar\Http\RateLimit\RateLimitResult;
use Pulsar\Observability\ErrorTracking\SensitiveDataScrubber;

#[CoversClass(MessageHandler::class)]
#[CoversClass(JsonRpcCodec::class)]
#[CoversClass(JsonRpcRequest::class)]
final class MessageHandlerTest extends TestCase
{
    private McpConfig $config;
    private SensitiveDataScrubber $scrubber;

    protected function setUp(): void
    {
        $this->scrubber = new SensitiveDataScrubber();

        $this->config = new McpConfig(
            enabled: true,
            clientId: 'test',
            projectRoot: '/tmp',
            tools: McpToolsConfig::fromArray([]),
            security: McpSecurityConfig::fromArray([]),
        );
    }

    private function createHandler(
        ?McpToolRegistryInterface $registry = null,
        ?ToolPermissionCheckerInterface $permissionChecker = null,
        ?McpAccessGateInterface $accessGate = null,
        ?McpRedactionPipelineInterface $redactionPipeline = null,
        ?AuditLoggerInterface $auditLogger = null,
        ?RateLimiterInterface $rateLimiter = null,
    ): MessageHandler {
        return new MessageHandler(
            registry: $registry ?? self::createStub(McpToolRegistryInterface::class),
            permissionChecker: $permissionChecker ?? self::createStub(ToolPermissionCheckerInterface::class),
            accessGate: $accessGate ?? self::createStub(McpAccessGateInterface::class),
            redactionPipeline: $redactionPipeline ?? self::createStub(McpRedactionPipelineInterface::class),
            auditLogger: $auditLogger ?? self::createStub(AuditLoggerInterface::class),
            rateLimiter: $rateLimiter ?? self::createStub(RateLimiterInterface::class),
            config: $this->config,
            scrubber: $this->scrubber,
        );
    }

    #[Test]
    public function handleInitializeWithKnownVersion(): void
    {
        $handler = $this->createHandler();

        $request = new JsonRpcRequest(id: 1, method: 'initialize', params: [
            'protocolVersion' => '2024-11-05',
        ]);

        $response = $handler->handle($request);

        self::assertNotNull($response);

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($response, true, 512, JSON_THROW_ON_ERROR);

        /** @var array<string, mixed> $result */
        $result = $decoded['result'];
        self::assertSame('2024-11-05', $result['protocolVersion']);

        /** @var array<string, mixed> $serverInfo */
        $serverInfo = $result['serverInfo'];
        self::assertSame('pulsar-mcp', $serverInfo['name']);
        self::assertArrayHasKey('capabilities', $result);
    }

    #[Test]
    public function handleInitializeWithUnknownValidDate(): void
    {
        $handler = $this->createHandler();

        $request = new JsonRpcRequest(id: 2, method: 'initialize', params: [
            'protocolVersion' => '2099-01-01',
        ]);

        $response = $handler->handle($request);

        self::assertNotNull($response);

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($response, true, 512, JSON_THROW_ON_ERROR);

        /** @var array<string, mixed> $result */
        $result = $decoded['result'];
        self::assertSame('2025-11-25', $result['protocolVersion']);
    }

    #[Test]
    public function handleInitializeWithMalformedVersion(): void
    {
        $handler = $this->createHandler();

        $request = new JsonRpcRequest(id: 3, method: 'initialize', params: [
            'protocolVersion' => 'not-a-date',
        ]);

        $response = $handler->handle($request);

        self::assertNotNull($response);

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($response, true, 512, JSON_THROW_ON_ERROR);

        self::assertArrayHasKey('error', $decoded);

        /** @var array<string, mixed> $error */
        $error = $decoded['error'];
        self::assertSame(-32602, $error['code']);
    }

    #[Test]
    public function handlePingReturnsEmptyResult(): void
    {
        $handler = $this->createHandler();

        $request = new JsonRpcRequest(id: 4, method: 'ping', params: []);

        $response = $handler->handle($request);

        self::assertNotNull($response);

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($response, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame([], $decoded['result']);
        self::assertSame(4, $decoded['id']);
    }

    #[Test]
    public function handleToolsListReturnsDefinitions(): void
    {
        $registry = self::createStub(McpToolRegistryInterface::class);

        $definitions = [
            new ToolDefinition(
                name: 'read_routes',
                description: 'Read registered routes',
                inputSchema: ['type' => 'object'],
                outputSchema: ['type' => 'object'],
                category: ToolCategory::Read,
            ),
        ];

        $registry->method('list')->willReturn($definitions);

        $handler = $this->createHandler(registry: $registry);

        $request = new JsonRpcRequest(id: 5, method: 'tools/list', params: []);

        $response = $handler->handle($request);

        self::assertNotNull($response);

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($response, true, 512, JSON_THROW_ON_ERROR);

        /** @var array<string, mixed> $result */
        $result = $decoded['result'];

        /** @var list<array<string, mixed>> $tools */
        $tools = $result['tools'];
        self::assertCount(1, $tools);
        self::assertSame('read_routes', $tools[0]['name']);
        self::assertSame('Read registered routes', $tools[0]['description']);
    }

    #[Test]
    public function handleToolsCallSuccess(): void
    {
        $toolResult = ToolResult::success(
            structuredContent: ['routes' => ['/api/health']],
            textContent: '1 route found',
        );

        $permissionChecker = self::createMock(ToolPermissionCheckerInterface::class);
        $permissionChecker->expects(self::once())
            ->method('assertAllowed')
            ->with('read_routes');

        $rateLimiter = self::createStub(RateLimiterInterface::class);
        $rateLimiter->method('hit')
            ->willReturn(new RateLimitResult(allowed: true, limit: 60, remaining: 59, retryAfter: 0));

        $registry = self::createStub(McpToolRegistryInterface::class);
        $registry->method('call')
            ->with('read_routes', [])
            ->willReturn($toolResult);

        $redactionPipeline = self::createStub(McpRedactionPipelineInterface::class);
        $redactionPipeline->method('redact')
            ->willReturnArgument(0);

        $handler = $this->createHandler(
            registry: $registry,
            permissionChecker: $permissionChecker,
            redactionPipeline: $redactionPipeline,
            rateLimiter: $rateLimiter,
        );

        $request = new JsonRpcRequest(id: 6, method: 'tools/call', params: [
            'name' => 'read_routes',
            'arguments' => [],
        ]);

        $response = $handler->handle($request);

        self::assertNotNull($response);

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($response, true, 512, JSON_THROW_ON_ERROR);

        /** @var array<string, mixed> $result */
        $result = $decoded['result'];

        /** @var list<array<string, mixed>> $content */
        $content = $result['content'];

        self::assertSame(6, $decoded['id']);
        self::assertFalse($result['isError']);
        self::assertSame('1 route found', $content[0]['text']);
        self::assertSame(['routes' => ['/api/health']], $result['structuredContent']);
    }

    #[Test]
    public function handleToolsCallPermissionDenied(): void
    {
        $permissionChecker = self::createMock(ToolPermissionCheckerInterface::class);
        $permissionChecker->expects(self::once())
            ->method('assertAllowed')
            ->with('run_tests')
            ->willThrowException(McpSecurityException::toolNotAllowed('run_tests'));

        $handler = $this->createHandler(permissionChecker: $permissionChecker);

        $request = new JsonRpcRequest(id: 7, method: 'tools/call', params: [
            'name' => 'run_tests',
            'arguments' => [],
        ]);

        $response = $handler->handle($request);

        self::assertNotNull($response);

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($response, true, 512, JSON_THROW_ON_ERROR);

        self::assertArrayHasKey('result', $decoded);

        /** @var array<string, mixed> $result */
        $result = $decoded['result'];

        /** @var list<array<string, mixed>> $content */
        $content = $result['content'];

        self::assertTrue($result['isError']);
        self::assertIsString($content[0]['text']);
        self::assertStringContainsString('not allowed', $content[0]['text']);
    }

    #[Test]
    public function handleNotificationReturnsNull(): void
    {
        $handler = $this->createHandler();

        $request = new JsonRpcRequest(id: null, method: 'notifications/initialized', params: []);

        $response = $handler->handle($request);

        self::assertNull($response);
    }
}
