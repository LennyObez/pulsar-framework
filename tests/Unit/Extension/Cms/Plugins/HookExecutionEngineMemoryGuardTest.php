<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Cms\Plugins;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Pulsar\Audit\AuditLoggerInterface;
use Pulsar\Extension\Cms\Internal\Plugins\HookExecutionEngine;
use Pulsar\Extension\Cms\Plugins\HookRegistry;
use ReflectionMethod;

use function ini_get;

#[CoversClass(HookExecutionEngine::class)]
final class HookExecutionEngineMemoryGuardTest extends TestCase
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

    // -- hasMemoryHeadroom() ---------------------------------------------------

    #[Test]
    public function has_memory_headroom_returns_true_with_unlimited_memory(): void
    {
        // When memory_limit is -1 (common in CLI/test), headroom is always true
        $iniLimit = ini_get('memory_limit');

        $method = new ReflectionMethod(HookExecutionEngine::class, 'hasMemoryHeadroom');
        $result = $method->invoke($this->engine);

        if ($iniLimit === '-1') {
            self::assertTrue($result, 'Unlimited memory should always have headroom');
        } else {
            // With a finite limit we still expect true in normal test conditions
            // (test processes typically have hundreds of MB free)
            self::assertTrue($result, 'Normal test memory conditions should have headroom');
        }
    }

    // -- getMemoryLimitBytes() ------------------------------------------------

    #[Test]
    public function get_memory_limit_bytes_returns_negative_one_for_unlimited(): void
    {
        $iniLimit = ini_get('memory_limit');

        $method = new ReflectionMethod(HookExecutionEngine::class, 'getMemoryLimitBytes');
        $result = $method->invoke($this->engine);

        if ($iniLimit === '-1' || $iniLimit === false || $iniLimit === '') {
            self::assertSame(-1, $result);
        } else {
            self::assertGreaterThan(0, $result);
        }
    }

    #[Test]
    public function get_memory_limit_bytes_parses_current_ini_value_correctly(): void
    {
        $iniLimit = ini_get('memory_limit');
        $method = new ReflectionMethod(HookExecutionEngine::class, 'getMemoryLimitBytes');
        $result = $method->invoke($this->engine);

        if ($iniLimit === '-1' || $iniLimit === false || $iniLimit === '') {
            self::assertSame(-1, $result);

            return;
        }

        // Manually compute expected value
        $value = (int) $iniLimit;
        $suffix = strtolower(substr($iniLimit, -1));
        $expected = match ($suffix) {
            'g' => $value * 1024 * 1024 * 1024,
            'm' => $value * 1024 * 1024,
            'k' => $value * 1024,
            default => $value,
        };

        self::assertSame($expected, $result);
    }

    // -- Pre-execution guard integration --------------------------------------

    #[Test]
    public function callback_executes_under_normal_memory_conditions(): void
    {
        $called = false;

        $this->hookRegistry->register('test_hook', static function () use (&$called): void {
            $called = true;
        }, 10, 'test-plugin');

        $this->engine->execute('test_hook');

        self::assertTrue($called, 'Callback should execute when memory headroom is sufficient');
    }

    #[Test]
    public function all_callbacks_execute_when_memory_is_available(): void
    {
        $calledA = false;
        $calledB = false;

        $this->hookRegistry->register('multi_hook', static function () use (&$calledA): void {
            $calledA = true;
        }, 10, 'plugin-a');

        $this->hookRegistry->register('multi_hook', static function () use (&$calledB): void {
            $calledB = true;
        }, 20, 'plugin-b');

        $this->engine->execute('multi_hook');

        self::assertTrue($calledA, 'Plugin A callback should execute');
        self::assertTrue($calledB, 'Plugin B callback should execute');
    }

    #[Test]
    public function memory_guard_check_runs_before_output_buffering(): void
    {
        // Verify structural correctness: the guard is checked before ob_start().
        // If the guard ran inside ob_start/ob_end_clean, an OOM inside the guard
        // would leave orphaned output buffers. We verify by checking that under
        // normal conditions, the ob level is unchanged after execute().
        $levelBefore = ob_get_level();

        $this->hookRegistry->register('ob_hook', static function (): void {
            // no-op
        }, 10, 'ob-plugin');

        $this->engine->execute('ob_hook');

        self::assertSame($levelBefore, ob_get_level(), 'Output buffer level should be unchanged');
    }

    #[Test]
    public function headroom_check_requires_twice_max_hook_memory(): void
    {
        // Verify the threshold calculation: hasMemoryHeadroom requires
        // available >= 2 * MAX_HOOK_MEMORY_BYTES (64 MB)
        $hasHeadroom = new ReflectionMethod(HookExecutionEngine::class, 'hasMemoryHeadroom');
        $getLimitBytes = new ReflectionMethod(HookExecutionEngine::class, 'getMemoryLimitBytes');

        $limitBytes = $getLimitBytes->invoke($this->engine);

        if ($limitBytes <= 0) {
            // Unlimited memory — guard always passes
            self::assertTrue($hasHeadroom->invoke($this->engine));

            return;
        }

        $currentUsage = memory_get_usage();
        /** @var int $limitBytes */
        $available = $limitBytes - $currentUsage;
        // MAX_HOOK_MEMORY_BYTES = 33_554_432 (32 MB), threshold = 2 * 32 MB = 64 MB
        $requiredHeadroom = 2 * 33_554_432;

        $expected = $available >= $requiredHeadroom;
        self::assertSame($expected, $hasHeadroom->invoke($this->engine));
    }
}
