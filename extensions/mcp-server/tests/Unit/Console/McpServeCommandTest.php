<?php

declare(strict_types=1);

namespace Pulsar\Extension\McpServer\Tests\Unit\Console;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Pulsar\Console\ExitCode;
use Pulsar\Console\InputInterface;
use Pulsar\Console\OutputInterface;
use Pulsar\Extension\McpServer\Console\McpServeCommand;
use Pulsar\Extension\McpServer\Contracts\McpAccessGateInterface;
use Pulsar\Extension\McpServer\Exception\McpSecurityException;
use ReflectionClass;

#[CoversClass(McpServeCommand::class)]
final class McpServeCommandTest extends TestCase
{
    private McpAccessGateInterface&Stub $accessGate;
    private InputInterface&Stub $input;
    private OutputInterface&Stub $output;

    protected function setUp(): void
    {
        $this->accessGate = $this->createStub(McpAccessGateInterface::class);
        $this->input = $this->createStub(InputInterface::class);
        $this->output = $this->createStub(OutputInterface::class);
    }

    #[Test]
    public function executeReturnsErrorWhenEnvironmentBlocked(): void
    {
        $this->accessGate->method('assertEnvironmentAllowed')
            ->willThrowException(McpSecurityException::environmentBlocked('production'));

        $command = $this->createCommandWithAccessGate($this->accessGate);

        $result = $command->execute($this->input, $this->output);

        self::assertSame(ExitCode::Error->value, $result);
    }

    #[Test]
    public function commandNameIsMcpServe(): void
    {
        $command = $this->createCommandWithAccessGate($this->accessGate);

        $ref = new ReflectionClass($command);
        $nameProp = $ref->getProperty('name');
        $name = $nameProp->getValue($command);

        self::assertSame('mcp:serve', $name);
    }

    #[Test]
    public function commandDescriptionIsSet(): void
    {
        $command = $this->createCommandWithAccessGate($this->accessGate);

        $ref = new ReflectionClass($command);
        $descProp = $ref->getProperty('description');
        $desc = $descProp->getValue($command);

        self::assertIsString($desc);
        self::assertNotEmpty($desc);
    }

    /**
     * Create a McpServeCommand using reflection to bypass the final readonly
     * class dependencies (MessageHandler and StdioTransport cannot be stubbed).
     */
    private function createCommandWithAccessGate(McpAccessGateInterface $accessGate): McpServeCommand
    {
        $ref = new ReflectionClass(McpServeCommand::class);
        /** @var McpServeCommand $command */
        $command = $ref->newInstanceWithoutConstructor();

        // Set the accessGate property via reflection
        $accessGateProp = $ref->getProperty('accessGate');
        $accessGateProp->setValue($command, $accessGate);

        // Call configure() to set name/description
        $configureMeth = $ref->getMethod('configure');
        $configureMeth->invoke($command);

        return $command;
    }
}
