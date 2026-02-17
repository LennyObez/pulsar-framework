<?php

declare(strict_types=1);

namespace Pulsar\Extension\McpServer\Tests\Unit\Internal\Protocol;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\McpServer\Config\McpConfig;
use Pulsar\Extension\McpServer\Contracts\McpRedactionPipelineInterface;
use Pulsar\Extension\McpServer\Contracts\McpToolRegistryInterface;
use Pulsar\Extension\McpServer\Contracts\ToolPermissionCheckerInterface;
use Pulsar\Extension\McpServer\Domain\ToolCategory;
use Pulsar\Extension\McpServer\Domain\ToolDefinition;
use Pulsar\Extension\McpServer\Domain\ToolResult;
use Pulsar\Extension\McpServer\Internal\Protocol\JsonRpcRequest;
use Pulsar\Extension\McpServer\Internal\Protocol\MessageHandler;
use Pulsar\Observability\ErrorTracking\SensitiveDataScrubber;

final class MessageHandlerTest extends TestCase
{
    private McpToolRegistryInterface&Stub $registry;
    private ToolPermissionCheckerInterface&Stub $permissionChecker;
    private McpRedactionPipelineInterface&Stub $redactionPipeline;
    private MessageHandler $handler;

    protected function setUp(): void
    {
        $this->registry = $this->createStub(McpToolRegistryInterface::class);
        $this->permissionChecker = $this->createStub(ToolPermissionCheckerInterface::class);
        $this->redactionPipeline = $this->createStub(McpRedactionPipelineInterface::class);

        $config = McpConfig::fromArray([], \Pulsar\Config\Environment::load());

        $this->handler = new MessageHandler(
            registry: $this->registry,
            permissionChecker: $this->permissionChecker,
            redactionPipeline: $this->redactionPipeline,
            auditLogger: null,
            rateLimiter: null,
            config: $config,
            scrubber: new SensitiveDataScrubber(),
        );
    }

    #[Test]
    public function handlePingReturnsResult(): void
    {
        $request = new JsonRpcRequest(
            method: 'ping',
            params: [],
            id: 1,
        );

        $response = $this->handler->handle($request);

        self::assertNotNull($response);
        self::assertStringContainsString('"result"', $response);
    }

    #[Test]
    public function handleInitializeReturnsServerInfo(): void
    {
        $request = new JsonRpcRequest(
            method: 'initialize',
            params: ['protocolVersion' => '2024-11-05'],
            id: 'init-1',
        );

        $response = $this->handler->handle($request);

        self::assertNotNull($response);
        self::assertStringContainsString('pulsar-mcp', $response);
        self::assertStringContainsString('protocolVersion', $response);
    }

    #[Test]
    public function handleInitializeRejectsInvalidProtocolVersion(): void
    {
        $request = new JsonRpcRequest(
            method: 'initialize',
            params: ['protocolVersion' => 'invalid'],
            id: 'init-2',
        );

        $response = $this->handler->handle($request);

        self::assertNotNull($response);
        self::assertStringContainsString('error', $response);
    }

    #[Test]
    public function handleInitializeRejectsMissingProtocolVersion(): void
    {
        $request = new JsonRpcRequest(
            method: 'initialize',
            params: [],
            id: 'init-3',
        );

        $response = $this->handler->handle($request);

        self::assertNotNull($response);
        self::assertStringContainsString('error', $response);
    }

    #[Test]
    public function handleToolsListReturnsToolDefinitions(): void
    {
        $toolDef = new ToolDefinition(
            name: 'test-tool',
            description: 'A test tool',
            inputSchema: ['type' => 'object'],
            outputSchema: [],
            category: ToolCategory::Read,
        );
        $this->registry->method('list')->willReturn([$toolDef]);

        $request = new JsonRpcRequest(
            method: 'tools/list',
            params: [],
            id: 'list-1',
        );

        $response = $this->handler->handle($request);

        self::assertNotNull($response);
        self::assertStringContainsString('test-tool', $response);
    }

    #[Test]
    public function handleToolsCallReturnsToolResult(): void
    {
        $result = ToolResult::success(['key' => 'value'], 'Output text');
        $this->registry->method('call')->willReturn($result);
        $this->redactionPipeline->method('redact')->willReturn($result);

        $request = new JsonRpcRequest(
            method: 'tools/call',
            params: ['name' => 'test-tool', 'arguments' => []],
            id: 'call-1',
        );

        $response = $this->handler->handle($request);

        self::assertNotNull($response);
        self::assertStringContainsString('Output text', $response);
    }

    #[Test]
    public function handleToolsCallRejectsEmptyToolName(): void
    {
        $request = new JsonRpcRequest(
            method: 'tools/call',
            params: ['name' => '', 'arguments' => []],
            id: 'call-2',
        );

        $response = $this->handler->handle($request);

        self::assertNotNull($response);
        self::assertStringContainsString('error', $response);
    }

    #[Test]
    public function handleToolsCallRejectsMissingToolName(): void
    {
        $request = new JsonRpcRequest(
            method: 'tools/call',
            params: [],
            id: 'call-3',
        );

        $response = $this->handler->handle($request);

        self::assertNotNull($response);
        self::assertStringContainsString('error', $response);
    }

    #[Test]
    public function handleUnknownMethodReturnsMethodNotFound(): void
    {
        $request = new JsonRpcRequest(
            method: 'unknown/method',
            params: [],
            id: 'unknown-1',
        );

        $response = $this->handler->handle($request);

        self::assertNotNull($response);
        self::assertStringContainsString('-32601', $response);
    }

    #[Test]
    public function handleNotificationReturnsNull(): void
    {
        $request = new JsonRpcRequest(
            method: 'notifications/initialized',
            params: [],
            id: null,
        );

        $response = $this->handler->handle($request);

        self::assertNull($response);
    }

    #[Test]
    public function handleCancelledNotificationReturnsNull(): void
    {
        $request = new JsonRpcRequest(
            method: 'notifications/cancelled',
            params: ['requestId' => 'req-1'],
            id: null,
        );

        $response = $this->handler->handle($request);

        self::assertNull($response);
    }
}
