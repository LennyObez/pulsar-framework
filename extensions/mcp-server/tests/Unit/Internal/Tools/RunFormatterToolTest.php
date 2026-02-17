<?php

declare(strict_types=1);

namespace Pulsar\Extension\McpServer\Tests\Unit\Internal\Tools;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\McpServer\Contracts\McpAccessGateInterface;
use Pulsar\Extension\McpServer\Contracts\McpRedactionPipelineInterface;
use Pulsar\Extension\McpServer\Domain\ToolCategory;
use Pulsar\Extension\McpServer\Exception\McpException;
use Pulsar\Extension\McpServer\Internal\Subprocess\SubprocessRunner;
use Pulsar\Extension\McpServer\Internal\Tools\RunFormatterTool;

#[CoversClass(RunFormatterTool::class)]
final class RunFormatterToolTest extends TestCase
{
    private RunFormatterTool $tool;

    protected function setUp(): void
    {
        $redaction = $this->createStub(McpRedactionPipelineInterface::class);
        $redaction->method('redactString')->willReturnArgument(0);

        $runner = new SubprocessRunner('.', 5, 1_048_576, $redaction);
        $accessGate = $this->createStub(McpAccessGateInterface::class);
        $this->tool = new RunFormatterTool($runner, $accessGate, 'composer', 'pnpm', '/project');
    }

    #[Test]
    public function nameReturnsPulsarFormatterRun(): void
    {
        self::assertSame('pulsar.formatter.run', $this->tool->name());
    }

    #[Test]
    public function categoryIsAction(): void
    {
        self::assertSame(ToolCategory::Action, $this->tool->category());
    }

    #[Test]
    public function inputSchemaRequiresType(): void
    {
        $schema = $this->tool->inputSchema();

        /** @var list<string> $required */
        $required = $schema['required'];
        self::assertContains('type', $required);
    }

    #[Test]
    public function executeRejectsInvalidType(): void
    {
        $this->expectException(McpException::class);
        $this->expectExceptionMessage('type must be');

        $this->tool->execute(['type' => 'ruby']);
    }

    #[Test]
    public function executeRejectsEmptyType(): void
    {
        $this->expectException(McpException::class);

        $this->tool->execute([]);
    }

    #[Test]
    public function outputSchemaHasExpectedFields(): void
    {
        $schema = $this->tool->outputSchema();

        /** @var array<string, mixed> $outProps */
        $outProps = $schema['properties'];
        self::assertArrayHasKey('exitCode', $outProps);
        self::assertArrayHasKey('stdout', $outProps);
        self::assertArrayHasKey('timedOut', $outProps);
    }

    #[Test]
    public function descriptionIsNonEmpty(): void
    {
        self::assertNotSame('', $this->tool->description());
    }
}
