<?php

declare(strict_types=1);

namespace Pulsar\Extension\Studio\Tests\Unit\Internal\Diagnostics;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\McpServer\Contracts\McpRedactionPipelineInterface;
use Pulsar\Extension\McpServer\Internal\Subprocess\SubprocessRunner;
use Pulsar\Extension\Studio\Contracts\ProcessRunnerInterface;
use Pulsar\Extension\Studio\Internal\Diagnostics\SubprocessRunnerAdapter;

#[CoversClass(SubprocessRunnerAdapter::class)]
final class SubprocessRunnerAdapterTest extends TestCase
{
    #[Test]
    public function implementsProcessRunnerInterface(): void
    {
        $redaction = $this->createStub(McpRedactionPipelineInterface::class);
        $redaction->method('redactString')->willReturnArgument(0);

        $runner = new SubprocessRunner(
            projectRoot: sys_get_temp_dir(),
            timeout: 5,
            maxOutputBytes: 1024,
            redactionPipeline: $redaction,
        );

        $adapter = new SubprocessRunnerAdapter($runner);

        self::assertInstanceOf(ProcessRunnerInterface::class, $adapter);
    }

    #[Test]
    public function runDelegatesToInnerRunnerWithEchoCommand(): void
    {
        $redaction = $this->createStub(McpRedactionPipelineInterface::class);
        $redaction->method('redactString')->willReturnArgument(0);

        $runner = new SubprocessRunner(
            projectRoot: sys_get_temp_dir(),
            timeout: 5,
            maxOutputBytes: 1024,
            redactionPipeline: $redaction,
        );

        $adapter = new SubprocessRunnerAdapter($runner);

        // Run a safe echo command
        $result = $adapter->run([PHP_BINARY, '-r', 'echo "hello-studio";']);

        self::assertFalse($result->isError);
        self::assertStringContainsString('hello-studio', $result->output);
    }

    #[Test]
    public function runMapsErrorResult(): void
    {
        $redaction = $this->createStub(McpRedactionPipelineInterface::class);
        $redaction->method('redactString')->willReturnArgument(0);

        $runner = new SubprocessRunner(
            projectRoot: sys_get_temp_dir(),
            timeout: 5,
            maxOutputBytes: 1024,
            redactionPipeline: $redaction,
        );

        $adapter = new SubprocessRunnerAdapter($runner);

        // Run a command that produces an error exit code
        $result = $adapter->run([PHP_BINARY, '-r', 'fwrite(STDERR, "error"); exit(1);']);

        self::assertTrue($result->isError);
    }
}
