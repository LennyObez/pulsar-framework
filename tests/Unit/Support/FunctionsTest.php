<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Support;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\ErrorHandling\HttpException;
use Pulsar\Support\Collection;
use Pulsar\Support\Pipeline;

use function assert;
use function is_string;

/**
 * Tests for global helper functions defined in src/Support/functions.php.
 */
final class FunctionsTest extends TestCase
{
    #[Test]
    public function collectCreatesCollection(): void
    {
        $c = collect([1, 2, 3]);

        self::assertInstanceOf(Collection::class, $c);
        self::assertSame([1, 2, 3], $c->toArray());
    }

    #[Test]
    public function collectWithEmptyArrayReturnsEmpty(): void
    {
        $c = collect();

        self::assertTrue($c->isEmpty());
    }

    #[Test]
    public function valueResolvesClosures(): void
    {
        self::assertSame(42, value(fn(): int => 42));
        self::assertSame('hello', value('hello'));
    }

    #[Test]
    public function onceMemorizesCallable(): void
    {
        $counter = 0;
        $fn = once(function () use (&$counter): int {
            $counter++;
            return 99;
        });

        self::assertSame(99, $fn());
        self::assertSame(99, $fn());
        self::assertSame(1, $counter);
    }

    #[Test]
    public function tapReturnsOriginalValue(): void
    {
        $sideEffect = null;
        $result = tap(42, function (int $v) use (&$sideEffect): void {
            $sideEffect = $v * 2;
        });

        self::assertSame(42, $result);
        self::assertSame(84, $sideEffect);
    }

    #[Test]
    public function pipelineReturnsInstance(): void
    {
        $p = pipeline('hello');

        self::assertInstanceOf(Pipeline::class, $p);
    }

    #[Test]
    public function pipelineWorksEndToEnd(): void
    {
        $result = pipeline('hello world')
            ->pipe(static function (mixed $s): string {
                assert(is_string($s));
                return strtoupper($s);
            })
            ->thenReturn();

        self::assertSame('HELLO WORLD', $result);
    }

    #[Test]
    public function abortThrowsHttpException(): void
    {
        $this->expectException(HttpException::class);

        abort(404, 'Not here');
    }

    #[Test]
    public function abortSetsCorrectStatusCode(): void
    {
        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('Forbidden');

        abort(403, 'Forbidden');
    }

    #[Test]
    public function abortSetsStatusCodeOnException(): void
    {
        $caught = null;

        try {
            abort(403, 'Forbidden');
        } catch (HttpException $e) {
            $caught = $e;
        }

        self::assertSame(403, $caught->getStatusCode()->value);
    }

    #[Test]
    public function abortIfThrowsWhenConditionIsTrue(): void
    {
        $this->expectException(HttpException::class);

        abort_if(true, 403);
    }

    #[Test]
    public function abortIfDoesNothingWhenConditionIsFalse(): void
    {
        abort_if(false, 403);

        $this->addToAssertionCount(1); // No exception means success
    }

    #[Test]
    public function abortUnlessThrowsWhenConditionIsFalse(): void
    {
        $this->expectException(HttpException::class);

        abort_unless(false, 401);
    }

    #[Test]
    public function abortUnlessDoesNothingWhenConditionIsTrue(): void
    {
        abort_unless(true, 401);

        $this->addToAssertionCount(1); // No exception means success
    }
}
