<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\McpServer\Console;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\OutputInterface;
use Pulsar\Extension\McpServer\Config\McpConfig;
use Pulsar\Extension\McpServer\Config\McpSecurityConfig;
use Pulsar\Extension\McpServer\Config\McpToolsConfig;
use Pulsar\Extension\McpServer\Console\McpServeCommand;
use Pulsar\Extension\McpServer\Contracts\McpAccessGateInterface;
use Pulsar\Extension\McpServer\Contracts\McpRedactionPipelineInterface;
use Pulsar\Extension\McpServer\Contracts\McpToolRegistryInterface;
use Pulsar\Extension\McpServer\Contracts\ToolPermissionCheckerInterface;
use Pulsar\Extension\McpServer\Exception\McpSecurityException;
use Pulsar\Extension\McpServer\Internal\Protocol\MessageHandler;
use Pulsar\Extension\McpServer\Internal\Protocol\StdioTransport;
use Pulsar\Observability\ErrorTracking\SensitiveDataScrubber;

#[CoversClass(McpServeCommand::class)]
final class McpServeCommandTest extends TestCase
{
    private function buildMessageHandler(): MessageHandler
    {
        $config = new McpConfig(
            enabled: true,
            clientId: 'test',
            projectRoot: '/tmp',
            tools: McpToolsConfig::fromArray([]),
            security: McpSecurityConfig::fromArray([]),
        );

        return new MessageHandler(
            registry: $this->createStub(McpToolRegistryInterface::class),
            permissionChecker: $this->createStub(ToolPermissionCheckerInterface::class),
            redactionPipeline: $this->createStub(McpRedactionPipelineInterface::class),
            auditLogger: null,
            rateLimiter: null,
            config: $config,
            scrubber: new SensitiveDataScrubber(),
        );
    }

    #[Test]
    public function commandIsNamedMcpServe(): void
    {
        $command = new McpServeCommand(
            $this->buildMessageHandler(),
            new StdioTransport(),
            $this->createStub(McpAccessGateInterface::class),
        );

        self::assertSame('mcp:serve', $command->name);
        self::assertNotEmpty($command->description);
    }

    #[Test]
    public function executeReturnsErrorWhenEnvironmentBlocked(): void
    {
        $accessGate = $this->createStub(McpAccessGateInterface::class);
        $accessGate->method('assertEnvironmentAllowed')
            ->willThrowException(McpSecurityException::environmentBlocked('production'));

        $output = $this->createMock(OutputInterface::class);
        $output->expects(self::once())
            ->method('errorln')
            ->with(self::stringContains('MCP server blocked'));

        $command = new McpServeCommand(
            $this->buildMessageHandler(),
            new StdioTransport(),
            $accessGate,
        );

        $result = $command->execute(
            $this->createStub(InputInterface::class),
            $output,
        );

        self::assertSame(ExitCode::Error->value, $result);
    }
}
