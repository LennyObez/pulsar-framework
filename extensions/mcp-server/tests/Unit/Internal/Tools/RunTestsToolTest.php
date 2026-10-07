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
use Pulsar\Extension\McpServer\Internal\Tools\RunTestsTool;

#[CoversClass(RunTestsTool::class)]
final class RunTestsToolTest extends TestCase
{
    private RunTestsTool $tool;

    protected function setUp(): void
    {
        $redaction = $this->createStub(McpRedactionPipelineInterface::class);
        $redaction->method('redactString')->willReturnArgument(0);

        $runner = new SubprocessRunner('.', 5, 1_048_576, $redaction);
        $accessGate = $this->createStub(McpAccessGateInterface::class);
        $this->tool = new RunTestsTool($runner, $accessGate, 'vendor/bin/phpunit', '/project');
    }

    #[Test]
    public function nameReturnsPulsarTestsRun(): void
    {
        self::assertSame('pulsar.tests.run', $this->tool->name());
    }

    #[Test]
    public function categoryIsAction(): void
    {
        self::assertSame(ToolCategory::Action, $this->tool->category());
    }

    #[Test]
    public function executeRejectsPathTraversal(): void
    {
        $this->expectException(McpException::class);
        $this->expectExceptionMessageIsOrContains('Path traversal');

        $this->tool->execute(['path' => '../../../etc/passwd']);
    }

    #[Test]
    public function inputSchemaHasFilterAndPathProperties(): void
    {
        $schema = $this->tool->inputSchema();

        /** @var array<string, mixed> $properties */
        $properties = $schema['properties'];
        self::assertArrayHasKey('filter', $properties);
        self::assertArrayHasKey('path', $properties);
    }

    #[Test]
    public function outputSchemaHasExpectedFields(): void
    {
        $schema = $this->tool->outputSchema();

        /** @var array<string, mixed> $outProps */
        $outProps = $schema['properties'];
        self::assertArrayHasKey('exitCode', $outProps);
        self::assertArrayHasKey('stdout', $outProps);
        self::assertArrayHasKey('stderr', $outProps);
    }

    #[Test]
    public function descriptionIsNonEmpty(): void
    {
        self::assertNotSame('', $this->tool->description());
    }
}
