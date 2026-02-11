<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\McpServer\Tools;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\McpServer\Contracts\McpAccessGateInterface;
use Pulsar\Extension\McpServer\Contracts\McpRedactionPipelineInterface;
use Pulsar\Extension\McpServer\Domain\ToolCategory;
use Pulsar\Extension\McpServer\Exception\McpException;
use Pulsar\Extension\McpServer\Internal\Subprocess\SubprocessRunner;
use Pulsar\Extension\McpServer\Internal\Tools\RunAnalysisTool;
use Pulsar\Extension\McpServer\Internal\Tools\RunFormatterTool;
use Pulsar\Extension\McpServer\Internal\Tools\RunTestsTool;

use function assert;
use function is_array;

#[CoversClass(RunAnalysisTool::class)]
#[CoversClass(RunFormatterTool::class)]
#[CoversClass(RunTestsTool::class)]
final class ActionToolsTest extends TestCase
{
    private SubprocessRunner $runner;
    private McpAccessGateInterface $accessGate;

    protected function setUp(): void
    {
        $redactionPipeline = $this->createStub(McpRedactionPipelineInterface::class);
        $redactionPipeline->method('redactString')->willReturnArgument(0);

        $this->runner = new SubprocessRunner(
            projectRoot: sys_get_temp_dir(),
            timeout: 5,
            maxOutputBytes: 1_048_576,
            redactionPipeline: $redactionPipeline,
        );
        $this->accessGate = $this->createStub(McpAccessGateInterface::class);
    }

    // --- RunAnalysisTool ---

    #[Test]
    public function analysisToolMetadata(): void
    {
        $tool = new RunAnalysisTool($this->runner, $this->accessGate, 'composer');

        self::assertSame('pulsar.analysis.run', $tool->name());
        self::assertNotEmpty($tool->description());
        self::assertSame(ToolCategory::Action, $tool->category());
        self::assertArrayHasKey('required', $tool->inputSchema());
        self::assertArrayHasKey('type', $tool->outputSchema());
    }

    #[Test]
    public function analysisToolInputSchemaRequiresAnalyzer(): void
    {
        $tool = new RunAnalysisTool($this->runner, $this->accessGate, 'composer');

        $schema = $tool->inputSchema();

        $required = $schema['required'];
        assert(is_array($required));
        self::assertContains('analyzer', $required);
        $properties = $schema['properties'];
        assert(is_array($properties));
        self::assertArrayHasKey('analyzer', $properties);
        $analyzer = $properties['analyzer'];
        assert(is_array($analyzer));
        self::assertSame(['phpstan', 'psalm'], $analyzer['enum']);
    }

    #[Test]
    public function analysisToolRejectsInvalidAnalyzer(): void
    {
        $tool = new RunAnalysisTool($this->runner, $this->accessGate, 'composer');

        $this->expectException(McpException::class);

        $tool->execute(['analyzer' => 'eslint']);
    }

    #[Test]
    public function analysisToolRejectsEmptyAnalyzer(): void
    {
        $tool = new RunAnalysisTool($this->runner, $this->accessGate, 'composer');

        $this->expectException(McpException::class);

        $tool->execute(['analyzer' => '']);
    }

    #[Test]
    public function analysisToolRejectsMissingAnalyzer(): void
    {
        $tool = new RunAnalysisTool($this->runner, $this->accessGate, 'composer');

        $this->expectException(McpException::class);

        $tool->execute([]);
    }

    #[Test]
    public function analysisToolRejectsNonStringAnalyzer(): void
    {
        $tool = new RunAnalysisTool($this->runner, $this->accessGate, 'composer');

        $this->expectException(McpException::class);

        $tool->execute(['analyzer' => 42]);
    }

    // --- RunFormatterTool ---

    #[Test]
    public function formatterToolMetadata(): void
    {
        $tool = new RunFormatterTool($this->runner, $this->accessGate, 'composer', 'pnpm', '/tmp/project');

        self::assertSame('pulsar.formatter.run', $tool->name());
        self::assertNotEmpty($tool->description());
        self::assertSame(ToolCategory::Action, $tool->category());
        self::assertArrayHasKey('required', $tool->inputSchema());
        self::assertArrayHasKey('type', $tool->outputSchema());
    }

    #[Test]
    public function formatterToolInputSchemaRequiresType(): void
    {
        $tool = new RunFormatterTool($this->runner, $this->accessGate, 'composer', 'pnpm', '/tmp/project');

        $schema = $tool->inputSchema();

        $required = $schema['required'];
        assert(is_array($required));
        self::assertContains('type', $required);
        $properties = $schema['properties'];
        assert(is_array($properties));
        self::assertArrayHasKey('type', $properties);
        self::assertArrayHasKey('path', $properties);
    }

    #[Test]
    public function formatterToolRejectsInvalidType(): void
    {
        $tool = new RunFormatterTool($this->runner, $this->accessGate, 'composer', 'pnpm', '/tmp/project');

        $this->expectException(McpException::class);

        $tool->execute(['type' => 'python']);
    }

    #[Test]
    public function formatterToolRejectsEmptyType(): void
    {
        $tool = new RunFormatterTool($this->runner, $this->accessGate, 'composer', 'pnpm', '/tmp/project');

        $this->expectException(McpException::class);

        $tool->execute(['type' => '']);
    }

    #[Test]
    public function formatterToolRejectsMissingType(): void
    {
        $tool = new RunFormatterTool($this->runner, $this->accessGate, 'composer', 'pnpm', '/tmp/project');

        $this->expectException(McpException::class);

        $tool->execute([]);
    }

    // --- RunTestsTool ---

    #[Test]
    public function testsToolMetadata(): void
    {
        $tool = new RunTestsTool($this->runner, $this->accessGate, 'vendor/bin/phpunit', '/tmp/project');

        self::assertSame('pulsar.tests.run', $tool->name());
        self::assertNotEmpty($tool->description());
        self::assertSame(ToolCategory::Action, $tool->category());
        self::assertArrayHasKey('type', $tool->inputSchema());
        self::assertArrayHasKey('type', $tool->outputSchema());
    }

    #[Test]
    public function testsToolInputSchemaHasFilterAndPath(): void
    {
        $tool = new RunTestsTool($this->runner, $this->accessGate, 'vendor/bin/phpunit', '/tmp/project');

        $schema = $tool->inputSchema();

        $properties = $schema['properties'];
        assert(is_array($properties));
        self::assertArrayHasKey('filter', $properties);
        self::assertArrayHasKey('path', $properties);
    }

    #[Test]
    public function testsToolRejectsInvalidFilter(): void
    {
        $tool = new RunTestsTool($this->runner, $this->accessGate, 'vendor/bin/phpunit', '/tmp/project');

        $this->expectException(McpException::class);

        $tool->execute(['filter' => '$(whoami)']);
    }

    #[Test]
    public function testsToolRejectsPathTraversal(): void
    {
        $tool = new RunTestsTool($this->runner, $this->accessGate, 'vendor/bin/phpunit', '/tmp/project');

        $this->expectException(McpException::class);

        $tool->execute(['path' => '../../etc/passwd']);
    }

    #[Test]
    public function testsToolRejectsSemicolonInFilter(): void
    {
        $tool = new RunTestsTool($this->runner, $this->accessGate, 'vendor/bin/phpunit', '/tmp/project');

        $this->expectException(McpException::class);

        $tool->execute(['filter' => 'Test;rm -rf /']);
    }

}
