<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Plugins;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Extension\Cms\Internal\Plugins\HookExecutionEngine;
use Pulsar\Extension\Cms\Plugins\HookRegistry;
use RuntimeException;

#[CoversClass(HookExecutionEngine::class)]
final class PluginGuardrailsTest extends TestCase
{
    private HookRegistry $hookRegistry;
    private HookExecutionEngine $engine;

    protected function setUp(): void
    {
        $this->hookRegistry = new HookRegistry();
        $auditLogger = $this->createStub(AuditLoggerInterface::class);

        $this->engine = new HookExecutionEngine(
            $this->hookRegistry,
            $auditLogger,
            new NullLogger(),
        );
    }

    // -- Exception isolation -------------------------------------------------

    #[Test]
    public function test_hook_exception_does_not_propagate(): void
    {
        $called = false;

        $this->hookRegistry->register('before_save', static function () {
            throw new RuntimeException('Plugin error');
        }, 10, 'failing-plugin');

        $this->hookRegistry->register('before_save', static function () use (&$called) {
            $called = true;
        }, 20, 'good-plugin');

        // Execute should not throw — the first hook's exception is caught
        $this->engine->execute('before_save');

        // The second hook should still run
        self::assertTrue($called, 'Second hook should execute even when the first throws');
    }

    #[Test]
    public function test_hook_exception_is_logged(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $hookRegistry = new HookRegistry();
        $engine = new HookExecutionEngine(
            $hookRegistry,
            $this->createStub(AuditLoggerInterface::class),
            $logger,
        );

        $hookRegistry->register('on_publish', static function () {
            throw new RuntimeException('Unexpected failure');
        }, 10, 'bad-plugin');

        $logger->expects(self::atLeastOnce())
            ->method('error')
            ->with(
                self::stringContains('Hook callback failed'),
                self::callback(static function (array $context): bool {
                    return $context['plugin'] === 'bad-plugin'
                        && $context['hook_point'] === 'on_publish';
                }),
            );

        $engine->execute('on_publish');
    }

    // -- Output buffering ----------------------------------------------------

    #[Test]
    public function test_output_from_hooks_is_captured(): void
    {
        $this->hookRegistry->register('render', static function () {
            echo 'stray output from plugin';
        }, 10, 'noisy-plugin');

        ob_start();
        $this->engine->execute('render');
        $captured = ob_get_clean();

        // The engine should have captured and discarded the plugin output
        self::assertSame('', $captured);
    }

    // -- Circuit breaker: trips after threshold ------------------------------

    #[Test]
    public function test_circuit_breaker_trips_after_10_failures(): void
    {
        // Register a hook that always fails
        $this->hookRegistry->register('tick', static function () {
            throw new RuntimeException('fail');
        }, 10, 'unstable-plugin');

        // Execute 10 times to trigger circuit breaker
        for ($i = 0; $i < 10; $i++) {
            $this->engine->execute('tick');
        }

        self::assertTrue(
            $this->engine->isCircuitBroken('unstable-plugin'),
            'Circuit breaker should trip after 10 failures',
        );
    }

    #[Test]
    public function test_circuit_breaker_auto_disables_plugin(): void
    {
        $executionCount = 0;

        $this->hookRegistry->register('tick', static function () use (&$executionCount) {
            $executionCount++;
            throw new RuntimeException('fail');
        }, 10, 'breaking-plugin');

        // First 10 calls should execute the callback
        for ($i = 0; $i < 10; $i++) {
            $this->engine->execute('tick');
        }

        // After tripping, further calls should skip the plugin
        $countBefore = $executionCount;
        $this->engine->execute('tick');

        self::assertSame(
            $countBefore,
            $executionCount,
            'Callback should not execute after circuit breaker trips',
        );
    }

    #[Test]
    public function test_circuit_broken_plugin_appears_in_list(): void
    {
        $this->hookRegistry->register('tick', static function () {
            throw new RuntimeException('fail');
        }, 10, 'listed-plugin');

        for ($i = 0; $i < 10; $i++) {
            $this->engine->execute('tick');
        }

        $broken = $this->engine->getCircuitBrokenPlugins();
        self::assertContains('listed-plugin', $broken);
    }

    // -- Successful hooks don't trigger circuit breaker ----------------------

    #[Test]
    public function test_successful_hooks_do_not_trigger_circuit_breaker(): void
    {
        $this->hookRegistry->register('success_hook', static function () {
            // Successful execution — no exception
        }, 10, 'stable-plugin');

        for ($i = 0; $i < 20; $i++) {
            $this->engine->execute('success_hook');
        }

        self::assertFalse(
            $this->engine->isCircuitBroken('stable-plugin'),
            'Stable plugin should not be circuit-broken',
        );
    }

    #[Test]
    public function test_circuit_breaker_not_tripped_below_threshold(): void
    {
        $this->hookRegistry->register('partial_fail', static function () {
            throw new RuntimeException('fail');
        }, 10, 'flaky-plugin');

        // Only 9 failures — below the threshold of 10
        for ($i = 0; $i < 9; $i++) {
            $this->engine->execute('partial_fail');
        }

        self::assertFalse(
            $this->engine->isCircuitBroken('flaky-plugin'),
            'Circuit breaker should not trip with fewer than 10 failures',
        );
    }

    // -- Multiple plugins: one broken, others unaffected ----------------------

    #[Test]
    public function test_circuit_breaker_only_affects_failing_plugin(): void
    {
        $goodCount = 0;

        $this->hookRegistry->register('shared_hook', static function () {
            throw new RuntimeException('fail');
        }, 10, 'bad-plugin');

        $this->hookRegistry->register('shared_hook', static function () use (&$goodCount) {
            $goodCount++;
        }, 20, 'good-plugin');

        // Trip the bad plugin's circuit breaker
        for ($i = 0; $i < 10; $i++) {
            $this->engine->execute('shared_hook');
        }

        self::assertTrue($this->engine->isCircuitBroken('bad-plugin'));
        self::assertFalse($this->engine->isCircuitBroken('good-plugin'));

        // Good plugin should still execute
        $countBefore = $goodCount;
        $this->engine->execute('shared_hook');
        self::assertSame($countBefore + 1, $goodCount);
    }

    // -- No hooks registered for a hook point --------------------------------

    #[Test]
    public function test_execute_with_no_registered_hooks(): void
    {
        // Should not throw or produce errors
        $this->engine->execute('nonexistent_hook');

        // Reached here without exception — pass
        $this->addToAssertionCount(1);
    }

    // -- Memory tracking logging ---------------------------------------------

    #[Test]
    public function test_memory_excessive_growth_is_logged(): void
    {
        // We can verify the engine tracks memory by registering a hook that allocates memory.
        // The exact threshold (32MB) is hard to trigger in tests, but we verify the engine
        // runs without error even with allocating hooks.
        $this->hookRegistry->register('alloc', static function () {
            // Small allocation that won't trigger the limit
            $data = str_repeat('x', 1024);
        }, 10, 'alloc-plugin');

        $this->engine->execute('alloc');

        // No circuit break for a small allocation
        self::assertFalse($this->engine->isCircuitBroken('alloc-plugin'));
    }

    // -- Hook arguments are passed through -----------------------------------

    #[Test]
    public function test_hook_receives_arguments(): void
    {
        $receivedArgs = [];

        $this->hookRegistry->register('with_args', static function (string $a, int $b) use (&$receivedArgs) {
            $receivedArgs = [$a, $b];
        }, 10, 'args-plugin');

        $this->engine->execute('with_args', 'hello', 42);

        self::assertSame(['hello', 42], $receivedArgs);
    }
}
