<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Concurrency;

use Fiber;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Concurrency\FanOut;
use Pulsar\Concurrency\FanOutResult;
use RuntimeException;

#[CoversClass(FanOut::class)]
#[CoversClass(FanOutResult::class)]
final class FanOutTest extends TestCase
{
    #[Test]
    public function it_returns_empty_array_for_empty_tasks(): void
    {
        $results = FanOut::run([]);

        self::assertSame([], $results);
    }

    #[Test]
    public function it_executes_single_task_successfully(): void
    {
        $results = FanOut::run([
            'task1' => static fn(): int => 42,
        ]);

        self::assertCount(1, $results);
        self::assertArrayHasKey('task1', $results);
        self::assertTrue($results['task1']->success);
        self::assertSame(42, $results['task1']->value);
        self::assertNull($results['task1']->error);
        self::assertFalse($results['task1']->timedOut);
    }

    #[Test]
    public function it_executes_multiple_tasks_successfully(): void
    {
        $results = FanOut::run([
            'a' => static fn(): string => 'alpha',
            'b' => static fn(): string => 'bravo',
            'c' => static fn(): string => 'charlie',
        ]);

        self::assertCount(3, $results);
        self::assertTrue($results['a']->success);
        self::assertSame('alpha', $results['a']->value);
        self::assertTrue($results['b']->success);
        self::assertSame('bravo', $results['b']->value);
        self::assertTrue($results['c']->success);
        self::assertSame('charlie', $results['c']->value);
    }

    #[Test]
    public function it_captures_exception_per_task(): void
    {
        $results = FanOut::run([
            'good' => static fn(): string => 'ok',
            'bad' => static fn(): never => throw new RuntimeException('fail'),
        ]);

        self::assertCount(2, $results);

        self::assertTrue($results['good']->success);
        self::assertSame('ok', $results['good']->value);

        self::assertFalse($results['bad']->success);
        self::assertInstanceOf(RuntimeException::class, $results['bad']->error);
        self::assertSame('fail', $results['bad']->error->getMessage());
        self::assertFalse($results['bad']->timedOut);
    }

    #[Test]
    public function it_handles_exception_without_affecting_other_tasks(): void
    {
        $results = FanOut::run([
            'first' => static fn(): int => 1,
            'throws' => static fn(): never => throw new RuntimeException('boom'),
            'last' => static fn(): int => 3,
        ]);

        self::assertCount(3, $results);
        self::assertTrue($results['first']->success);
        self::assertSame(1, $results['first']->value);
        self::assertFalse($results['throws']->success);
        self::assertTrue($results['last']->success);
        self::assertSame(3, $results['last']->value);
    }

    #[Test]
    public function it_preserves_key_order(): void
    {
        $results = FanOut::run([
            'z' => static fn(): string => 'last',
            'a' => static fn(): string => 'first',
            'm' => static fn(): string => 'middle',
        ]);

        $keys = array_keys($results);
        self::assertSame(['z', 'a', 'm'], $keys);
    }

    #[Test]
    public function it_supports_integer_keys(): void
    {
        $results = FanOut::run([
            0 => static fn(): string => 'zero',
            1 => static fn(): string => 'one',
            2 => static fn(): string => 'two',
        ]);

        self::assertCount(3, $results);
        self::assertTrue($results[0]->success);
        self::assertSame('zero', $results[0]->value);
    }

    #[Test]
    public function it_handles_tasks_that_return_null(): void
    {
        $results = FanOut::run([
            'null_task' => static function (): mixed {
                return null;
            },
        ]);

        self::assertCount(1, $results);
        self::assertTrue($results['null_task']->success);
        self::assertNull($results['null_task']->value);
    }

    #[Test]
    public function it_handles_tasks_that_use_fiber_suspend(): void
    {
        $results = FanOut::run([
            'suspending' => static function (): string {
                Fiber::suspend();

                return 'resumed';
            },
        ]);

        self::assertCount(1, $results);
        self::assertTrue($results['suspending']->success);
        self::assertSame('resumed', $results['suspending']->value);
    }

    #[Test]
    public function fan_out_result_stores_all_fields(): void
    {
        $error = new RuntimeException('test');

        $success = new FanOutResult(success: true, value: 'hello');
        self::assertTrue($success->success);
        self::assertSame('hello', $success->value);
        self::assertNull($success->error);
        self::assertFalse($success->timedOut);

        $failure = new FanOutResult(success: false, error: $error);
        self::assertFalse($failure->success);
        self::assertNull($failure->value);
        self::assertSame($error, $failure->error);
        self::assertFalse($failure->timedOut);

        $timeout = new FanOutResult(success: false, timedOut: true);
        self::assertFalse($timeout->success);
        self::assertTrue($timeout->timedOut);
    }
}
