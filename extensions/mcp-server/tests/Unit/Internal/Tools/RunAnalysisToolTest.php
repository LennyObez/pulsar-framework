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
use Pulsar\Extension\McpServer\Internal\Tools\RunAnalysisTool;

#[CoversClass(RunAnalysisTool::class)]
final class RunAnalysisToolTest extends TestCase
{
    private RunAnalysisTool $tool;

    protected function setUp(): void
    {
        $redaction = $this->createStub(McpRedactionPipelineInterface::class);
        $redaction->method('redactString')->willReturnArgument(0);

        $runner = new SubprocessRunner('.', 5, 1_048_576, $redaction);
        $accessGate = $this->createStub(McpAccessGateInterface::class);
        $this->tool = new RunAnalysisTool($runner, $accessGate, 'composer');
    }

    #[Test]
    public function nameReturnsPulsarAnalysisRun(): void
    {
        self::assertSame('pulsar.analysis.run', $this->tool->name());
    }

    #[Test]
    public function categoryIsAction(): void
    {
        self::assertSame(ToolCategory::Action, $this->tool->category());
    }

    #[Test]
    public function inputSchemaRequiresAnalyzer(): void
    {
        $schema = $this->tool->inputSchema();

        /** @var list<string> $required */
        $required = $schema['required'];
        self::assertContains('analyzer', $required);
        /** @var array<string, mixed> $properties */
        $properties = $schema['properties'];
        self::assertArrayHasKey('analyzer', $properties);
    }

    #[Test]
    public function executeRejectsInvalidAnalyzer(): void
    {
        $this->expectException(McpException::class);
        $this->expectExceptionMessageIsOrContains('analyzer must be');

        $this->tool->execute(['analyzer' => 'invalid']);
    }

    #[Test]
    public function executeRejectsEmptyAnalyzer(): void
    {
        $this->expectException(McpException::class);

        $this->tool->execute([]);
    }

    #[Test]
    public function descriptionIsNonEmpty(): void
    {
        self::assertNotSame('', $this->tool->description());
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
}
