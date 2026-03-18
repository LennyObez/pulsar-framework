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

        // SEC-IPC-01: build the child environment from an explicit allowlist
        // instead of inheriting the parent's full env. The MCP server is invoked
        // by potentially untrusted clients and the previous `array_merge(getenv(),
        // ['CI' => 1], $env)` leaked every secret in the runner's environment
        // (COMPOSER_AUTH, GITHUB_TOKEN, AWS_SECRET_ACCESS_KEY, …) into every
        // tool subprocess. The allowlist below is the minimum set of variables a
        // sane PHP/composer/git tool needs to run; everything else must be
        // declared explicitly in `$env` by the caller.
        $mergedEnv = $this->buildChildEnvironment($env);

        // Array form ($command is `list<string>`) bypasses shell
        // interpretation entirely — proc_open hands argv directly
        // to execvp. Each argument is a separate string with no
        // splitting / globbing / variable expansion, so command
        // injection via a $command element is structurally
        // impossible. The McpAccessGate caller validates the path
        // and arguments via ParamValidator before reaching here.
        // nosemgrep: php.lang.security.exec-use.exec-use
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
                $this->forceTerminate($process, $status);

                break;
            }

            if ($nowNs >= $deadline) {
                $timedOut = true;
                $this->forceTerminate($process, $status);

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
                $this->forceTerminate($process, $status);

                break;
            }

            usleep(10_000);
        }

        // F32.5: close the pipes BEFORE proc_close so a child still
        // blocked on `write()` to a full pipe gets SIGPIPE and exits
        // promptly. Otherwise proc_close waits for the child, the
        // child waits for a pipe drain, and the runner deadlocks.
        fclose($pipes[1]);
        fclose($pipes[2]);

        // F32.6: SIGTERM (proc_terminate's default) is just a
        // request — a misbehaving / hung child can install a
        // handler that ignores it. Wait briefly for the
        // already-issued terminate to land, then escalate to
        // SIGKILL on UNIX (signal 9). On Windows proc_terminate
        // uses TerminateProcess which is already unavoidable, so
        // the second call is a no-op there. Without the
        // escalation a hung child becomes a zombie that the LLM
        // workflow can accumulate by spamming tool calls.
        $exitCode = $this->awaitExit($process);

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
     * F32.6: wait for an already-terminating subprocess and
     * escalate to SIGKILL if it does not exit within the grace
     * window. proc_terminate's default signal is SIGTERM, which
     * a misbehaving child can install a handler for and ignore;
     * SIGKILL bypasses any handler and is the cooperative-process
     * contract end-state.
     *
     * F32.17: on Windows, `proc_open` wraps every command through
     * `cmd.exe /c`. `proc_terminate` then kills the cmd.exe
     * wrapper but the actual child PHP process can keep running
     * to completion — a `sleep(10)` survives a 1s timeout. Walk
     * the process tree via `taskkill /T /F /PID` (called through
     * proc_open in array form so the PID flows in as a separate
     * argv entry and never touches a shell). On UNIX,
     * `proc_terminate` already targets the right pgid, so the
     * standard path is sufficient.
     *
     * @param resource $process
     */
    private function awaitExit($process): int
    {
        $graceDeadlineNs = hrtime(true) + 1_500_000_000; // 1.5s

        while (hrtime(true) < $graceDeadlineNs) {
            $status = proc_get_status($process);
            if (!$status['running']) {
                return proc_close($process);
            }

            usleep(50_000);
        }

        $status = proc_get_status($process);
        $pid = is_array($status) && isset($status['pid']) && is_int($status['pid'])
            ? $status['pid']
            : 0;

        if (PHP_OS_FAMILY === 'Windows' && $pid > 0) {
            $this->windowsForceKillTree($pid);
        } else {
            proc_terminate($process, defined('SIGKILL') ? SIGKILL : 9);
        }

        return proc_close($process);
    }

    /**
     * F32.17: terminate the subprocess unconditionally,
     * walking the tree on Windows so the cmd.exe wrapper
     * AND the actual child both die. On UNIX, proc_terminate
     * already targets the right pgid.
     *
     * This is the fast-path used by the timeout / cancel /
     * cap-exceeded branches; awaitExit() handles the slow-path
     * grace+escalate sequence for cooperative shutdowns.
     *
     * @param resource $process
     * @param array<string, mixed>|false $status proc_get_status() snapshot
     */
    private function forceTerminate($process, array|false $status): void
    {
        $pid = is_array($status) && isset($status['pid']) && is_int($status['pid'])
            ? $status['pid']
            : 0;

        if (PHP_OS_FAMILY === 'Windows' && $pid > 0) {
            $this->windowsForceKillTree($pid);
            return;
        }

        proc_terminate($process);
    }

    /**
     * SEC-IPC-01: assemble the child process environment from an explicit
     * allowlist of inherited variables plus the caller-supplied overrides.
     *
     * The allowlist names variables that must flow into composer / git / PHP
     * subprocesses to function (e.g. PATH for executable resolution, HOME for
     * dotfile lookup, COMPOSER_HOME for cache reuse). Everything else from the
     * runner's env (secrets, CI tokens, AWS credentials, etc.) is dropped.
     *
     * Caller overrides win over the inherited values; the special CI=1 flag
     * is appended so existing tooling that branches on it keeps working.
     *
     * @param array<string, string> $callerEnv
     * @return array<string, string>
     */
    private function buildChildEnvironment(array $callerEnv): array
    {
        $inheritAllowlist = [
            'PATH',
            'PATHEXT',
            'HOME',
            'USERPROFILE',
            'TEMP',
            'TMP',
            'TMPDIR',
            'LANG',
            'LC_ALL',
            'TZ',
            'SYSTEMROOT',
            'COMSPEC',
            'COMPOSER_HOME',
            'COMPOSER_CACHE_DIR',
            'XDG_CACHE_HOME',
            'XDG_CONFIG_HOME',
        ];

        $inherited = [];

        foreach ($inheritAllowlist as $name) {
            $value = getenv($name);

            if ($value !== false && $value !== '') {
                $inherited[$name] = $value;
            }
        }

        return array_merge($inherited, ['CI' => '1'], $callerEnv);
    }

    /**
     * F32.17: force-kill the cmd.exe wrapper PHP gave us AND
     * its descendants on Windows. Uses proc_open array form so
     * the PID flows as a separate argv entry — taskkill receives
     * it positionally, never via shell expansion. Output is
     * discarded; the call is best-effort because the most common
     * case is "child already died from the earlier SIGTERM" and
     * taskkill's "process not found" exit is expected.
     */
    private function windowsForceKillTree(int $pid): void
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        // nosemgrep: php.lang.security.exec-use.exec-use
        $handle = proc_open(
            ['taskkill', '/T', '/F', '/PID', (string) $pid],
            $descriptors,
            $pipes,
        );

        if (!is_resource($handle)) {
            return;
        }

        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }

        proc_close($handle);
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
