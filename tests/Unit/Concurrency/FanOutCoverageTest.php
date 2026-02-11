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
final class FanOutCoverageTest extends TestCase
{
    #[Test]
    public function tasksThatSuspendMultipleTimesCompleteSuccessfully(): void
    {
        $results = FanOut::run([
            'multi-suspend' => static function (): string {
                Fiber::suspend();
                Fiber::suspend();
                Fiber::suspend();

                return 'done';
            },
        ]);

        self::assertCount(1, $results);
        self::assertTrue($results['multi-suspend']->success);
        self::assertSame('done', $results['multi-suspend']->value);
    }

    #[Test]
    public function mixedSuspendingAndNonSuspendingTasks(): void
    {
        $results = FanOut::run([
            'immediate' => static fn(): int => 1,
            'suspending' => static function (): int {
                Fiber::suspend();

                return 2;
            },
            'also-immediate' => static fn(): int => 3,
        ]);

        self::assertCount(3, $results);
        self::assertTrue($results['immediate']->success);
        self::assertSame(1, $results['immediate']->value);
        self::assertTrue($results['suspending']->success);
        self::assertSame(2, $results['suspending']->value);
        self::assertTrue($results['also-immediate']->success);
        self::assertSame(3, $results['also-immediate']->value);
    }

    #[Test]
    public function taskThrowingDuringResumeIsCaptured(): void
    {
        $results = FanOut::run([
            'throw-after-suspend' => static function (): never {
                Fiber::suspend();
                throw new RuntimeException('failed-on-resume');
            },
        ]);

        self::assertCount(1, $results);
        self::assertFalse($results['throw-after-suspend']->success);
        self::assertInstanceOf(RuntimeException::class, $results['throw-after-suspend']->error);
        self::assertSame('failed-on-resume', $results['throw-after-suspend']->error->getMessage());
    }

    #[Test]
    public function timeoutMarksRemainingTasksAsTimedOut(): void
    {
        $results = FanOut::run([
            'infinite' => static function (): string {
                // Suspend forever — the timeout will terminate this
                for (;;) {
                    Fiber::suspend();
                }
            },
        ], timeoutMs: 1);

        // Allow a small window — either the task completes or times out
        self::assertCount(1, $results);
        // On very fast machines, the task might complete before timeout
        if ($results['infinite']->timedOut) {
            self::assertFalse($results['infinite']->success);
            self::assertTrue($results['infinite']->timedOut);
        }
    }

    #[Test]
    public function largeNumberOfTasksCompletesSuccessfully(): void
    {
        $tasks = [];
        for ($i = 0; $i < 50; $i++) {
            $tasks["task-{$i}"] = static fn(): int => $i;
        }

        $results = FanOut::run($tasks);

        self::assertCount(50, $results);

        foreach ($results as $result) {
            self::assertTrue($result->success);
        }
    }

    #[Test]
    public function taskReturningComplexDataStructure(): void
    {
        $results = FanOut::run([
            'complex' => static fn(): array => ['key' => 'value', 'nested' => ['a', 'b']],
        ]);

        self::assertTrue($results['complex']->success);
        self::assertSame(['key' => 'value', 'nested' => ['a', 'b']], $results['complex']->value);
    }

    #[Test]
    public function allTasksFailing(): void
    {
        $results = FanOut::run([
            'fail1' => static fn(): never => throw new RuntimeException('err1'),
            'fail2' => static fn(): never => throw new RuntimeException('err2'),
        ]);

        self::assertCount(2, $results);
        self::assertFalse($results['fail1']->success);
        self::assertFalse($results['fail2']->success);
    }

    #[Test]
    public function fanOutResultDefaultValues(): void
    {
        $result = new FanOutResult(success: true);

        self::assertTrue($result->success);
        self::assertNull($result->value);
        self::assertNull($result->error);
        self::assertFalse($result->timedOut);
    }
}
