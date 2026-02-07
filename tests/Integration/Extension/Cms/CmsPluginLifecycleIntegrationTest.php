<?php

declare(strict_types=1);

namespace Pulsar\Tests\Integration\Extension\Cms;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Extension\Cms\Internal\Plugins\HookExecutionEngine;
use Pulsar\Extension\Cms\Plugins\HookRegistry;
use RuntimeException;

/**
 * Integration tests for the CMS plugin lifecycle including hook execution,
 * circuit breaker behavior, and error isolation.
 */
#[CoversClass(HookExecutionEngine::class)]
#[CoversClass(HookRegistry::class)]
final class CmsPluginLifecycleIntegrationTest extends TestCase
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

    // -- Full lifecycle: register → hook fires → disable --------------------

    #[Test]
    public function test_full_plugin_lifecycle_hook_fires(): void
    {
        $fireCount = 0;

        // Step 1: Register a hook (simulating plugin.register())
        $this->hookRegistry->register('content.before_save', static function () use (&$fireCount) {
            $fireCount++;
        }, 10, 'lifecycle-plugin');

        // Step 2: Verify hook is registered
        self::assertTrue($this->hookRegistry->has('content.before_save'));

        // Step 3: Execute hook (simulating a content save)
        $this->engine->execute('content.before_save');
        self::assertSame(1, $fireCount, 'Hook should have fired during content save');

        // Step 4: Verify multiple executions work
        $this->engine->execute('content.before_save');
        self::assertSame(2, $fireCount, 'Hook should fire on subsequent calls');
    }

    #[Test]
    public function test_multiple_plugins_register_same_hook_point(): void
    {
        $order = [];

        $this->hookRegistry->register('content.after_publish', static function () use (&$order) {
            $order[] = 'plugin-a';
        }, 20, 'plugin-a');

        $this->hookRegistry->register('content.after_publish', static function () use (&$order) {
            $order[] = 'plugin-b';
        }, 10, 'plugin-b');

        $this->hookRegistry->register('content.after_publish', static function () use (&$order) {
            $order[] = 'plugin-c';
        }, 30, 'plugin-c');

        $this->engine->execute('content.after_publish');

        // Should be sorted by priority (ascending): b(10), a(20), c(30)
        self::assertSame(['plugin-b', 'plugin-a', 'plugin-c'], $order);
    }

    // -- Circuit breaker trip and auto-disable ------------------------------

    #[Test]
    public function test_circuit_breaker_trip_stops_hook_execution(): void
    {
        $callCount = 0;

        $this->hookRegistry->register('tick', static function () use (&$callCount) {
            $callCount++;
            throw new RuntimeException('Plugin crash');
        }, 10, 'crashy-plugin');

        // Execute 10 times to trip the circuit breaker
        for ($i = 0; $i < 10; $i++) {
            $this->engine->execute('tick');
        }

        self::assertSame(10, $callCount, 'Hook should have been called 10 times before tripping');
        self::assertTrue($this->engine->isCircuitBroken('crashy-plugin'));

        // After tripping, further executions should NOT call the hook
        $this->engine->execute('tick');
        self::assertSame(10, $callCount, 'Hook should not execute after circuit breaker trip');
    }

    #[Test]
    public function test_circuit_breaker_only_affects_the_failing_plugin(): void
    {
        $healthyCount = 0;

        $this->hookRegistry->register('shared', static function () {
            throw new RuntimeException('crash');
        }, 10, 'failing-plugin');

        $this->hookRegistry->register('shared', static function () use (&$healthyCount) {
            $healthyCount++;
        }, 20, 'healthy-plugin');

        // Trip circuit breaker for failing-plugin
        for ($i = 0; $i < 10; $i++) {
            $this->engine->execute('shared');
        }

        self::assertTrue($this->engine->isCircuitBroken('failing-plugin'));
        self::assertFalse($this->engine->isCircuitBroken('healthy-plugin'));

        // Healthy plugin should still run
        $countBefore = $healthyCount;
        $this->engine->execute('shared');
        self::assertSame($countBefore + 1, $healthyCount);
    }

    // -- Error isolation with output buffering ------------------------------

    #[Test]
    public function test_error_isolation_with_output_buffering(): void
    {
        $secondHookCalled = false;

        $this->hookRegistry->register('render', static function () {
            echo 'should be captured';
            throw new RuntimeException('render error');
        }, 10, 'noisy-failing-plugin');

        $this->hookRegistry->register('render', static function () use (&$secondHookCalled) {
            $secondHookCalled = true;
        }, 20, 'clean-plugin');

        ob_start();
        $this->engine->execute('render');
        $output = ob_get_clean();

        // Output should be empty (captured and discarded by engine)
        self::assertSame('', $output);
        // Second hook should still run
        self::assertTrue($secondHookCalled);
    }

    // -- Hook points management ---------------------------------------------

    #[Test]
    public function test_hook_registry_tracks_hook_points(): void
    {
        $this->hookRegistry->register('init', static function () {}, 10, 'p1');
        $this->hookRegistry->register('shutdown', static function () {}, 10, 'p2');
        $this->hookRegistry->register('init', static function () {}, 20, 'p3');

        $points = $this->hookRegistry->getHookPoints();

        self::assertContains('init', $points);
        self::assertContains('shutdown', $points);
    }

    #[Test]
    public function test_unregistered_hook_point_has_no_callbacks(): void
    {
        self::assertFalse($this->hookRegistry->has('nonexistent'));
        self::assertSame([], $this->hookRegistry->getCallbacks('nonexistent'));
    }

    // -- Arguments pass-through in lifecycle --------------------------------

    #[Test]
    public function test_hook_receives_content_data(): void
    {
        $receivedTitle = '';

        $this->hookRegistry->register('content.before_save', static function (array $data) use (&$receivedTitle) {
            $receivedTitle = $data['title'] ?? '';
        }, 10, 'content-plugin');

        $this->engine->execute('content.before_save', ['title' => 'Hello World']);

        self::assertSame('Hello World', $receivedTitle);
    }

    // -- Mixed success/failure in same hook point ---------------------------

    #[Test]
    public function test_mixed_success_and_failure_hooks(): void
    {
        $results = [];

        $this->hookRegistry->register('process', static function () use (&$results) {
            $results[] = 'first-ok';
        }, 10, 'plugin-1');

        $this->hookRegistry->register('process', static function () {
            throw new RuntimeException('fail');
        }, 20, 'plugin-2');

        $this->hookRegistry->register('process', static function () use (&$results) {
            $results[] = 'third-ok';
        }, 30, 'plugin-3');

        $this->engine->execute('process');

        // First and third should succeed; second should fail silently
        self::assertSame(['first-ok', 'third-ok'], $results);
    }
}
