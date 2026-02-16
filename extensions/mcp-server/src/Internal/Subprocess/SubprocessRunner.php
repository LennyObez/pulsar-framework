<?php

declare(strict_types=1);

namespace Pulsar\Extension\McpServer\Internal\Subprocess;

use LogicException;
use Pulsar\Api\Internal;
use Pulsar\Extension\McpServer\Contracts\McpRedactionPipelineInterface;
use Pulsar\Extension\McpServer\Domain\ToolResult;

use function array_merge;
use function fclose;
use function fread;
use function getenv;
use function hrtime;
use function is_resource;
use function max;
use function proc_close;
use function proc_get_status;
use function proc_open;
use function proc_terminate;
use function spl_object_id;
use function stream_set_blocking;
use function strlen;
use function substr;
use function usleep;

/**
 * Executes external processes with timeout, output capping, and automatic redaction.
 *
 * All subprocess output is passed through the redaction pipeline before being
 * returned, ensuring sensitive data never reaches the MCP wire protocol.
 *
 * **Cancellation contract**: A single SubprocessRunner instance MUST be shared
 * between the fiber that calls {@see run()} and the fiber/handler that calls
 * {@see cancel()}. The `$cancelled` flag is polled inside the run loop, so
 * calling `cancel()` on a different instance has no effect. Use the same
 * object reference in both the spawning fiber and the MCP
 * `notifications/cancelled` handler.
 */
#[Internal(reason: 'MCP subprocess execution; not part of public API')]
final class SubprocessRunner
{
    private bool $cancelled = false;

    /**
     * Object ID captured at construction time. Used by {@see assertSameInstance()}
     * to verify that the caller holds a reference to this exact instance.
     */
    private readonly int $identity;

    /**
     * @param string $projectRoot Working directory for subprocess execution
     * @param int $timeout Maximum execution time in seconds
     * @param int $maxOutputBytes Maximum combined stdout+stderr bytes before truncation
     * @param McpRedactionPipelineInterface $redactionPipeline Pipeline for redacting sensitive output
     */
    public function __construct(
        private readonly string $projectRoot,
        private readonly int $timeout,
        private readonly int $maxOutputBytes,
        private readonly McpRedactionPipelineInterface $redactionPipeline,
    ) {
        $this->identity = spl_object_id($this);
    }

    /**
     * Assert that the given reference points to this exact instance.
     *
     * Call this in the cancellation handler to guard against accidentally
     * holding a stale or cloned runner reference. Throws if the object
     * identities do not match.
     *
     * @throws LogicException When the reference is not the same instance
     */
    public function assertSameInstance(self $other): void
    {
        if ($this->identity !== $other->identity) {
            throw new LogicException(
                'SubprocessRunner cancellation requires the same instance that called run(). '
                . 'Ensure the spawning fiber and the cancellation handler share one reference.',
            );
        }
    }

    /**
     * Run a subprocess command with timeout and output capping.
     *
     * @param list<string> $command Command and arguments (array form for proc_open)
     * @param array<string, string> $env Additional environment variables
     */
    public function run(array $command, array $env = []): ToolResult
    {
        $this->cancelled = false;

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $osEnv = getenv();
        $mergedEnv = array_merge($osEnv, ['CI' => '1'], $env);

        $process = proc_open($command, $descriptors, $pipes, $this->projectRoot, $mergedEnv);

        if (!is_resource($process)) {
            return ToolResult::error('Failed to start subprocess');
        }

        fclose($pipes[0]);

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $timedOut = false;
        $startTime = hrtime(true);
        $deadline = $startTime + ($this->timeout * 1_000_000_000);

        while (true) {
            $status = proc_get_status($process);
            $nowNs = hrtime(true);

            if ($this->isCancelled()) {
                proc_terminate($process);

                break;
            }

            if ($nowNs >= $deadline) {
                $timedOut = true;
                proc_terminate($process);

                break;
            }

            $chunk1 = fread($pipes[1], 8192);
            $chunk2 = fread($pipes[2], 8192);

            if ($chunk1 !== false && $chunk1 !== '') {
                $stdout .= $chunk1;
            }

            if ($chunk2 !== false && $chunk2 !== '') {
                $stderr .= $chunk2;
            }

            if (!$status['running']) {
                break;
            }

            if (strlen($stdout) + strlen($stderr) > $this->maxOutputBytes) {
                break;
            }

            usleep(10_000);
        }

        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        $totalBytes = strlen($stdout) + strlen($stderr);
        $truncated = false;

        if ($totalBytes > $this->maxOutputBytes) {
            $truncated = true;
            $stdout = substr($stdout, 0, $this->maxOutputBytes);
            $stderr = substr($stderr, 0, max(0, $this->maxOutputBytes - strlen($stdout)));
        }

        $stdout = $this->redactionPipeline->redactString($stdout);
        $stderr = $this->redactionPipeline->redactString($stderr);

        $structured = [
            'exitCode' => $timedOut ? -1 : $exitCode,
            'stdout' => $stdout,
            'stderr' => $stderr,
            'timedOut' => $timedOut,
            'wasCancelled' => $this->cancelled,
            'truncated' => $truncated,
        ];

        $text = $stdout !== '' ? $stdout : $stderr;

        if ($timedOut) {
            return ToolResult::error('Process timed out after ' . $this->timeout . 's', ['timedOut' => true]);
        }

        if ($truncated) {
            return ToolResult::truncated($structured, $text, $totalBytes, strlen($stdout) + strlen($stderr));
        }

        return new ToolResult(
            structuredContent: $structured,
            textContent: $text,
            isError: $exitCode !== 0,
            meta: [],
        );
    }

    /**
     * Check if cancellation has been requested (by another Fiber).
     */
    private function isCancelled(): bool
    {
        return $this->cancelled;
    }

    /**
     * Best-effort cancellation of the active subprocess.
     *
     * Used by the notifications/cancelled handler to terminate a running process.
     */
    public function cancel(): void
    {
        $this->cancelled = true;
    }
}
