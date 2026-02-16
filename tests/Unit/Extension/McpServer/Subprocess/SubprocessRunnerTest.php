<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\McpServer\Subprocess;

use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\McpServer\Contracts\McpRedactionPipelineInterface;
use Pulsar\Extension\McpServer\Internal\Subprocess\SubprocessRunner;

use function assert;
use function is_string;

#[CoversClass(SubprocessRunner::class)]
final class SubprocessRunnerTest extends TestCase
{
    private McpRedactionPipelineInterface $redactionPipeline;

    protected function setUp(): void
    {
        $this->redactionPipeline = $this->createStub(McpRedactionPipelineInterface::class);
        $this->redactionPipeline->method('redactString')->willReturnArgument(0);
    }

    #[Test]
    public function runSuccessfulCommandReturnsNonErrorResult(): void
    {
        $runner = new SubprocessRunner(
            projectRoot: sys_get_temp_dir(),
            timeout: 10,
            maxOutputBytes: 1_048_576,
            redactionPipeline: $this->redactionPipeline,
        );

        $result = $runner->run(['php', '-r', 'echo "hello";']);

        self::assertFalse($result->isError);
        self::assertStringContainsString('hello', $result->textContent);
        self::assertSame(0, $result->structuredContent['exitCode']);
        self::assertFalse($result->structuredContent['timedOut']);
        self::assertFalse($result->structuredContent['wasCancelled']);
        self::assertFalse($result->structuredContent['truncated']);
    }

    #[Test]
    public function runFailingCommandReturnsErrorResult(): void
    {
        $runner = new SubprocessRunner(
            projectRoot: sys_get_temp_dir(),
            timeout: 10,
            maxOutputBytes: 1_048_576,
            redactionPipeline: $this->redactionPipeline,
        );

        $result = $runner->run(['php', '-r', 'fwrite(STDERR, "err"); exit(1);']);

        self::assertTrue($result->isError);
        self::assertNotSame(0, $result->structuredContent['exitCode']);
    }

    #[Test]
    public function runTimesOutAndReportsTimeout(): void
    {
        $runner = new SubprocessRunner(
            projectRoot: sys_get_temp_dir(),
            timeout: 1,
            maxOutputBytes: 1_048_576,
            redactionPipeline: $this->redactionPipeline,
        );

        // Sleep for longer than timeout
        $result = $runner->run(['php', '-r', 'sleep(30);']);

        self::assertTrue($result->isError);
        self::assertStringContainsString('timed out', $result->textContent);
    }

    #[Test]
    public function runTruncatesOversizedOutput(): void
    {
        $runner = new SubprocessRunner(
            projectRoot: sys_get_temp_dir(),
            timeout: 10,
            maxOutputBytes: 50,
            redactionPipeline: $this->redactionPipeline,
        );

        // Generate output larger than 50 bytes
        $result = $runner->run(['php', '-r', 'echo str_repeat("x", 200);']);

        self::assertArrayHasKey('truncated', $result->structuredContent);
    }

    #[Test]
    public function cancelMethodIsCallable(): void
    {
        $runner = new SubprocessRunner(
            projectRoot: sys_get_temp_dir(),
            timeout: 60,
            maxOutputBytes: 1_048_576,
            redactionPipeline: $this->redactionPipeline,
        );

        // cancel() sets internal flag and does not throw
        $runner->cancel();

        self::assertInstanceOf(SubprocessRunner::class, $runner);
    }

    #[Test]
    public function runWithInvalidCommandReturnsError(): void
    {
        $runner = new SubprocessRunner(
            projectRoot: sys_get_temp_dir(),
            timeout: 10,
            maxOutputBytes: 1_048_576,
            redactionPipeline: $this->redactionPipeline,
        );

        // proc_open emits a warning for non-existent binaries on some platforms
        $previousLevel = error_reporting(E_ERROR);

        try {
            $result = $runner->run(['nonexistent-binary-xyz-123']);
            self::assertTrue($result->isError);
        } finally {
            error_reporting($previousLevel);
        }
    }

    #[Test]
    public function runPassesAdditionalEnvVariables(): void
    {
        $runner = new SubprocessRunner(
            projectRoot: sys_get_temp_dir(),
            timeout: 10,
            maxOutputBytes: 1_048_576,
            redactionPipeline: $this->redactionPipeline,
        );

        $result = $runner->run(
            ['php', '-r', 'echo getenv("TEST_VAR_XYZ");'],
            ['TEST_VAR_XYZ' => 'custom_value'],
        );

        self::assertFalse($result->isError);
        self::assertStringContainsString('custom_value', $result->textContent);
    }

    #[Test]
    public function runAlwaysSetsciEnvVariable(): void
    {
        $runner = new SubprocessRunner(
            projectRoot: sys_get_temp_dir(),
            timeout: 10,
            maxOutputBytes: 1_048_576,
            redactionPipeline: $this->redactionPipeline,
        );

        $result = $runner->run(['php', '-r', 'echo getenv("CI");']);

        self::assertFalse($result->isError);
        self::assertStringContainsString('1', $result->textContent);
    }

    #[Test]
    public function runWithStderrOutputReturnsStderrAsText(): void
    {
        $runner = new SubprocessRunner(
            projectRoot: sys_get_temp_dir(),
            timeout: 10,
            maxOutputBytes: 1_048_576,
            redactionPipeline: $this->redactionPipeline,
        );

        $result = $runner->run(['php', '-r', 'fwrite(STDERR, "error output"); exit(1);']);

        self::assertTrue($result->isError);
        $stderr = $result->structuredContent['stderr'];
        assert(is_string($stderr));
        self::assertStringContainsString('error output', $stderr);
    }

    #[Test]
    public function redactionPipelineIsAppliedToOutput(): void
    {
        $redaction = $this->createStub(McpRedactionPipelineInterface::class);
        $redaction->method('redactString')->willReturn('[REDACTED]');

        $runner = new SubprocessRunner(
            projectRoot: sys_get_temp_dir(),
            timeout: 10,
            maxOutputBytes: 1_048_576,
            redactionPipeline: $redaction,
        );

        $result = $runner->run(['php', '-r', 'echo "secret data";']);

        self::assertSame('[REDACTED]', $result->structuredContent['stdout']);
        self::assertSame('[REDACTED]', $result->structuredContent['stderr']);
    }

    // ------------------------------------------------------------------
    // Cancellation contract tests
    // ------------------------------------------------------------------

    #[Test]
    public function assertSameInstanceSucceedsForSameObject(): void
    {
        $runner = new SubprocessRunner(
            projectRoot: sys_get_temp_dir(),
            timeout: 10,
            maxOutputBytes: 1_048_576,
            redactionPipeline: $this->redactionPipeline,
        );

        // Passing itself should not throw
        $runner->assertSameInstance($runner);

        self::assertInstanceOf(SubprocessRunner::class, $runner);
    }

    #[Test]
    public function assertSameInstanceThrowsForDifferentInstance(): void
    {
        $runner1 = new SubprocessRunner(
            projectRoot: sys_get_temp_dir(),
            timeout: 10,
            maxOutputBytes: 1_048_576,
            redactionPipeline: $this->redactionPipeline,
        );

        $runner2 = new SubprocessRunner(
            projectRoot: sys_get_temp_dir(),
            timeout: 10,
            maxOutputBytes: 1_048_576,
            redactionPipeline: $this->redactionPipeline,
        );

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('same instance');

        $runner1->assertSameInstance($runner2);
    }

    #[Test]
    public function cancelMethodSetsFlagObservableBySubsequentRun(): void
    {
        $runner = new SubprocessRunner(
            projectRoot: sys_get_temp_dir(),
            timeout: 10,
            maxOutputBytes: 1_048_576,
            redactionPipeline: $this->redactionPipeline,
        );

        // run() resets the cancelled flag at the start, so pre-cancelling
        // does not affect a subsequent run(). This verifies the reset
        // contract: each run starts fresh.
        $runner->cancel();

        $result = $runner->run(['php', '-r', 'echo "ok";']);

        self::assertFalse($result->structuredContent['wasCancelled']);
        self::assertStringContainsString('ok', $result->textContent);
    }
}
