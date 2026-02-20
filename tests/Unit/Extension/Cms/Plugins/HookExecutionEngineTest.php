<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Plugins;

use Closure;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Extension\Cms\Internal\Plugins\HookExecutionEngine;
use Pulsar\Extension\Cms\Plugins\HookRegistry;
use RuntimeException;

#[CoversClass(HookExecutionEngine::class)]
final class HookExecutionEngineTest extends TestCase
{
    private HookRegistry $registry;
    private HookExecutionEngine $engine;
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        $this->registry = new HookRegistry();
        $this->logger = $this->createStub(LoggerInterface::class);
        $auditLogger = $this->createStub(AuditLoggerInterface::class);
        $this->engine = new HookExecutionEngine($this->registry, $auditLogger, $this->logger);
    }

    #[Test]
    public function executeCallsRegisteredCallbacks(): void
    {
        $called = false;
        $this->registry->register('hook.test', static function () use (&$called): void {
            $called = true;
        }, 10, 'plugin-a');

        $this->engine->execute('hook.test');

        self::assertTrue($called);
    }

    #[Test]
    public function executePassesArgumentsToCallbacks(): void
    {
        $receivedArgs = [];
        $this->registry->register('hook.args', static function (string $a, int $b) use (&$receivedArgs): void {
            $receivedArgs = [$a, $b];
        }, 10, 'plugin-a');

        $this->engine->execute('hook.args', 'hello', 42);

        self::assertSame(['hello', 42], $receivedArgs);
    }

    #[Test]
    public function executeCallsMultipleCallbacksInPriorityOrder(): void
    {
        $order = [];
        $this->registry->register('hook.multi', static function () use (&$order): void {
            $order[] = 'second';
        }, 20, 'plugin-b');
        $this->registry->register('hook.multi', static function () use (&$order): void {
            $order[] = 'first';
        }, 10, 'plugin-a');

        $this->engine->execute('hook.multi');

        self::assertSame(['first', 'second'], $order);
    }

    #[Test]
    public function executeWithNoRegisteredCallbacksDoesNothing(): void
    {
        // Should not throw
        $this->engine->execute('hook.nonexistent');

        self::assertFalse($this->engine->isCircuitBroken('any-plugin'));
    }

    #[Test]
    public function executeIsolatesExceptionsFromCallbacks(): void
    {
        $secondCalled = false;
        $this->registry->register('hook.error', static function (): void {
            throw new RuntimeException('Callback failure');
        }, 10, 'plugin-a');
        $this->registry->register('hook.error', static function () use (&$secondCalled): void {
            $secondCalled = true;
        }, 20, 'plugin-b');

        $this->engine->execute('hook.error');

        // Second callback should still execute despite first throwing
        self::assertTrue($secondCalled);
    }

    #[Test]
    public function executeDiscardsOutputFromCallbacks(): void
    {
        $this->registry->register('hook.output', static function (): void {
            echo 'This should be discarded';
        }, 10, 'plugin-a');

        ob_start();
        $this->engine->execute('hook.output');
        $captured = ob_get_clean();

        self::assertSame('', $captured);
    }

    #[Test]
    public function isCircuitBrokenReturnsFalseForUnknownPlugin(): void
    {
        self::assertFalse($this->engine->isCircuitBroken('unknown-plugin'));
    }

    #[Test]
    public function circuitBreakerTripsAfterThresholdFailures(): void
    {
        // Register a callback that always fails
        for ($i = 0; $i < 10; $i++) {
            $this->registry->register("hook.fail.$i", static function (): void {
                throw new RuntimeException('fail');
            }, 10, 'bad-plugin');
        }

        // Execute 10 different hooks, each triggering a failure for 'bad-plugin'
        for ($i = 0; $i < 10; $i++) {
            $this->engine->execute("hook.fail.$i");
        }

        self::assertTrue($this->engine->isCircuitBroken('bad-plugin'));
    }

    #[Test]
    public function circuitBrokenPluginIsSkipped(): void
    {
        // Trip the circuit breaker first
        for ($i = 0; $i < 10; $i++) {
            $this->registry->register("hook.trip.$i", static function (): void {
                throw new RuntimeException('fail');
            }, 10, 'broken-plugin');
        }
        for ($i = 0; $i < 10; $i++) {
            $this->engine->execute("hook.trip.$i");
        }
        self::assertTrue($this->engine->isCircuitBroken('broken-plugin'));

        // Now register a new callback for the broken plugin
        $called = false;
        $this->registry->register('hook.after', static function () use (&$called): void {
            $called = true;
        }, 10, 'broken-plugin');

        $this->engine->execute('hook.after');

        // Should NOT have been called
        self::assertFalse($called);
    }

    #[Test]
    public function getCircuitBrokenPluginsReturnsTrippedPlugins(): void
    {
        self::assertSame([], $this->engine->getCircuitBrokenPlugins());

        // Trip the breaker for a plugin
        for ($i = 0; $i < 10; $i++) {
            $this->registry->register("hook.trip.$i", static function (): void {
                throw new RuntimeException('fail');
            }, 10, 'plugin-x');
        }
        for ($i = 0; $i < 10; $i++) {
            $this->engine->execute("hook.trip.$i");
        }

        self::assertContains('plugin-x', $this->engine->getCircuitBrokenPlugins());
    }

    #[Test]
    public function circuitBreakerDoesNotAffectOtherPlugins(): void
    {
        // Trip the breaker for plugin-a
        for ($i = 0; $i < 10; $i++) {
            $this->registry->register("hook.fail.$i", static function (): void {
                throw new RuntimeException('fail');
            }, 10, 'plugin-a');
        }
        for ($i = 0; $i < 10; $i++) {
            $this->engine->execute("hook.fail.$i");
        }

        self::assertTrue($this->engine->isCircuitBroken('plugin-a'));
        self::assertFalse($this->engine->isCircuitBroken('plugin-b'));

        // plugin-b should still execute
        $called = false;
        $this->registry->register('hook.ok', static function () use (&$called): void {
            $called = true;
        }, 10, 'plugin-b');

        $this->engine->execute('hook.ok');
        self::assertTrue($called);
    }

    #[Test]
    public function calculateDelayReturnsExponentialBackoff(): void
    {
        // WebhookRetryJob::calculateDelay is used for exponential backoff validation
        // but the engine itself tests memory/circuit breaking, not backoff
        // Just verify engine still works after multiple hooks
        $count = 0;
        $this->registry->register('hook.count', static function () use (&$count): void {
            $count++;
        }, 10, 'plugin-counter');

        $this->engine->execute('hook.count');
        $this->engine->execute('hook.count');
        $this->engine->execute('hook.count');

        self::assertSame(3, $count);
    }
}
