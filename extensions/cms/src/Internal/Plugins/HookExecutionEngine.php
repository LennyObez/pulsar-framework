<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Internal\Plugins;

use Psr\Log\LoggerInterface;
use Pulsar\Api\Internal;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Extension\Cms\Plugins\HookRegistry;
use Pulsar\Security\Audit\AuditEvent;
use Pulsar\Security\Audit\AuditOutcome;
use RuntimeException;
use Throwable;

use function count;
use function ini_get;
use function memory_get_usage;
use function ob_end_clean;
use function ob_start;
use function sprintf;
use function strtolower;
use function substr;

/**
 * Executes registered hooks with guardrails: output buffering, memory limits,
 * circuit breaker integration, and per-callback error isolation.
 *
 * @psalm-api Resolved from the DI container by CmsPluginManager; not
 *            instantiated by name.
 */
#[Internal(reason: 'Hook execution internals; not a public API')]
final class HookExecutionEngine
{
    /** Maximum memory a single hook callback may consume (32 MB). */
    private const int MAX_HOOK_MEMORY_BYTES = 33_554_432;

    /** Circuit breaker threshold: failures before auto-disable. */
    private const int CIRCUIT_BREAKER_FAILURE_THRESHOLD = 10;

    /** Circuit breaker window in seconds. */
    private const int CIRCUIT_BREAKER_WINDOW_SECONDS = 300;

    /** @var array<string, list<int>> Plugin slug => list of failure timestamps. */
    private array $failureTimestamps = [];

    /** @var array<string, bool> Plugin slugs that have been circuit-broken. */
    private array $circuitBroken = [];

    public function __construct(
        private readonly HookRegistry $hookRegistry,
        private readonly ?AuditLoggerInterface $auditLogger,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Execute all callbacks for a hook point with guardrails.
     *
     * @param mixed ...$args Arguments passed to each callback
     */
    public function execute(string $hookPoint, mixed ...$args): void
    {
        $callbacks = $this->hookRegistry->getCallbacks($hookPoint);

        foreach ($callbacks as $entry) {
            $pluginSlug = $entry['pluginSlug'];

            // Skip if circuit breaker has tripped for this plugin
            if ($this->isCircuitBroken($pluginSlug)) {
                continue;
            }

            // Pre-execution memory guard: skip if insufficient headroom
            if (!$this->hasMemoryHeadroom()) {
                $this->logger->warning('Skipping hook callback: insufficient memory headroom', [
                    'plugin' => $pluginSlug,
                    'hook_point' => $hookPoint,
                    'memory_usage' => memory_get_usage(),
                ]);
                $this->recordFailure(
                    $pluginSlug,
                    $hookPoint,
                    new RuntimeException(sprintf(
                        'Skipped hook callback from plugin "%s": insufficient memory headroom (%d bytes used)',
                        $pluginSlug,
                        memory_get_usage(),
                    )),
                );
                continue;
            }

            $memoryBefore = memory_get_usage();

            // Capture any output to prevent corruption
            ob_start();

            try {
                ($entry['callback'])(...$args);
            } catch (Throwable $e) {
                $this->recordFailure($pluginSlug, $hookPoint, $e);
            } finally {
                // Discard any output from the hook callback
                ob_end_clean();
            }

            // Check memory usage
            $memoryAfter = memory_get_usage();
            $memoryUsed = $memoryAfter - $memoryBefore;

            if ($memoryUsed > self::MAX_HOOK_MEMORY_BYTES) {
                $this->logger->warning('Hook callback exceeded memory limit', [
                    'plugin' => $pluginSlug,
                    'hook_point' => $hookPoint,
                    'memory_used' => $memoryUsed,
                    'memory_limit' => self::MAX_HOOK_MEMORY_BYTES,
                ]);

                $this->recordFailure(
                    $pluginSlug,
                    $hookPoint,
                    new RuntimeException(sprintf(
                        'Hook callback from plugin "%s" used %d bytes (limit: %d)',
                        $pluginSlug,
                        $memoryUsed,
                        self::MAX_HOOK_MEMORY_BYTES,
                    )),
                );
            }
        }
    }

    /**
     * Check if a plugin's circuit breaker has tripped.
     */
    public function isCircuitBroken(string $pluginSlug): bool
    {
        return $this->circuitBroken[$pluginSlug] ?? false;
    }

    /**
     * Get the list of circuit-broken plugin slugs.
     *
     * @return list<string>
     */
    public function getCircuitBrokenPlugins(): array
    {
        return array_keys(array_filter($this->circuitBroken));
    }

    /**
     * Record a failure for a plugin and check the circuit breaker threshold.
     */
    private function recordFailure(string $pluginSlug, string $hookPoint, Throwable $error): void
    {
        $now = time();

        $this->logger->error('Hook callback failed', [
            'plugin' => $pluginSlug,
            'hook_point' => $hookPoint,
            'error' => $error->getMessage(),
        ]);

        $this->auditLogger?->log(
            AuditEvent::SystemEvent,
            AuditOutcome::Error,
            null,
            'cms.plugin.hook_failed',
            "plugin:$pluginSlug",
            [
                'hook_point' => $hookPoint,
                'error' => $error->getMessage(),
            ],
        );

        // Track failure timestamps
        if (!isset($this->failureTimestamps[$pluginSlug])) {
            $this->failureTimestamps[$pluginSlug] = [];
        }

        $this->failureTimestamps[$pluginSlug][] = $now;

        // Prune old timestamps outside the window
        $cutoff = $now - self::CIRCUIT_BREAKER_WINDOW_SECONDS;
        $this->failureTimestamps[$pluginSlug] = array_values(
            array_filter(
                $this->failureTimestamps[$pluginSlug],
                static fn(int $ts): bool => $ts >= $cutoff,
            ),
        );

        // Check threshold
        if (count($this->failureTimestamps[$pluginSlug]) >= self::CIRCUIT_BREAKER_FAILURE_THRESHOLD) {
            $this->circuitBroken[$pluginSlug] = true;

            $this->logger->critical('Plugin circuit breaker tripped: auto-disabling', [
                'plugin' => $pluginSlug,
                'failures_in_window' => count($this->failureTimestamps[$pluginSlug]),
            ]);

            $this->auditLogger?->log(
                AuditEvent::SecurityEvent,
                AuditOutcome::Failure,
                null,
                'cms.plugin.circuit_breaker_tripped',
                "plugin:$pluginSlug",
                [
                    'failures_in_window' => count($this->failureTimestamps[$pluginSlug]),
                    'window_seconds' => self::CIRCUIT_BREAKER_WINDOW_SECONDS,
                ],
            );
        }
    }

    /**
     * Check if enough memory remains to safely execute a hook callback.
     * Requires at least 2x MAX_HOOK_MEMORY_BYTES of free memory.
     */
    private function hasMemoryHeadroom(): bool
    {
        $memoryLimit = $this->getMemoryLimitBytes();
        if ($memoryLimit <= 0) {
            // Unlimited memory (-1 in php.ini): always allow
            return true;
        }

        $currentUsage = memory_get_usage();
        $available = $memoryLimit - $currentUsage;

        return $available >= (2 * self::MAX_HOOK_MEMORY_BYTES);
    }

    /**
     * Parse PHP memory_limit into bytes.
     */
    private function getMemoryLimitBytes(): int
    {
        $limit = ini_get('memory_limit');
        if ($limit === false || $limit === '' || $limit === '-1') {
            return -1;
        }

        $value = (int) $limit;
        $suffix = strtolower(substr($limit, -1));

        return match ($suffix) {
            'g' => $value * 1024 * 1024 * 1024,
            'm' => $value * 1024 * 1024,
            'k' => $value * 1024,
            default => $value,
        };
    }
}
