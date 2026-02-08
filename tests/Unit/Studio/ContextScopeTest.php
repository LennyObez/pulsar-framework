<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Studio;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Studio\ContextScope;
use RuntimeException;

#[CoversClass(ContextScope::class)]
final class ContextScopeTest extends TestCase
{
    #[Test]
    public function closeInvokesCallback(): void
    {
        $called = false;

        $scope = new ContextScope(function () use (&$called): void {
            $called = true;
        });

        /** @var bool $calledBefore */
        $calledBefore = $called;
        self::assertFalse($calledBefore);

        $scope->close();

        /** @var bool $calledAfter */
        $calledAfter = $called;
        self::assertTrue($calledAfter);
    }

    #[Test]
    public function closeIsIdempotent(): void
    {
        $callCount = 0;

        $scope = new ContextScope(function () use (&$callCount): void {
            $callCount++;
        });

        $scope->close();
        $scope->close();
        $scope->close();

        self::assertSame(1, $callCount);
    }

    #[Test]
    public function closeOnlyInvokesCallbackOnce(): void
    {
        $invocations = [];

        $scope = new ContextScope(function () use (&$invocations): void {
            $invocations[] = microtime(true);
        });

        $scope->close();
        $scope->close();
        $scope->close();
        $scope->close();
        $scope->close();

        self::assertCount(1, $invocations);
    }

    #[Test]
    public function callbackReceivesNoArguments(): void
    {
        /** @var list<mixed>|null $args */
        $args = null;

        $scope = new ContextScope(function (mixed ...$receivedArgs) use (&$args): void {
            $args = $receivedArgs;
        });

        $scope->close();

        self::assertSame([], $args);
    }

    #[Test]
    public function callbackCanModifyExternalState(): void
    {
        $counter = 10;

        $scope = new ContextScope(function () use (&$counter): void {
            $counter = 20;
        });

        $scope->close();

        self::assertSame(20, $counter);
    }

    #[Test]
    public function multipleScopesAreIndependent(): void
    {
        $scope1Called = false;
        $scope2Called = false;

        $scope1 = new ContextScope(function () use (&$scope1Called): void {
            $scope1Called = true;
        });

        $scope2 = new ContextScope(function () use (&$scope2Called): void {
            $scope2Called = true;
        });

        $scope1->close();

        /** @var bool $s1CalledAfterClose */
        $s1CalledAfterClose = $scope1Called;
        /** @var bool $s2CalledAfterClose */
        $s2CalledAfterClose = $scope2Called;
        self::assertTrue($s1CalledAfterClose);
        self::assertFalse($s2CalledAfterClose);

        $scope2->close();

        /** @var bool $s2FinalState */
        $s2FinalState = $scope2Called;
        self::assertTrue($s2FinalState);
    }

    #[Test]
    public function closingInReverseOrderWorks(): void
    {
        $order = [];

        $scope1 = new ContextScope(function () use (&$order): void {
            $order[] = 1;
        });

        $scope2 = new ContextScope(function () use (&$order): void {
            $order[] = 2;
        });

        $scope3 = new ContextScope(function () use (&$order): void {
            $order[] = 3;
        });

        // Close in reverse order (LIFO)
        $scope3->close();
        $scope2->close();
        $scope1->close();

        self::assertSame([3, 2, 1], $order);
    }

    #[Test]
    public function closingInArbitraryOrderWorks(): void
    {
        $order = [];

        $scope1 = new ContextScope(function () use (&$order): void {
            $order[] = 1;
        });

        $scope2 = new ContextScope(function () use (&$order): void {
            $order[] = 2;
        });

        $scope3 = new ContextScope(function () use (&$order): void {
            $order[] = 3;
        });

        // Close in arbitrary order
        $scope2->close();
        $scope1->close();
        $scope3->close();

        self::assertSame([2, 1, 3], $order);
    }

    #[Test]
    public function scopeCanBeUsedInTryFinally(): void
    {
        $cleaned = false;

        $scope = new ContextScope(function () use (&$cleaned): void {
            $cleaned = true;
        });

        try {
            // Simulate some work
            $result = 1 + 1;
            self::assertSame(2, $result);
        } finally {
            $scope->close();
        }

        self::assertTrue($cleaned);
    }

    #[Test]
    public function scopeCleanupWorksEvenWithException(): void
    {
        $cleaned = false;

        $scope = new ContextScope(function () use (&$cleaned): void {
            $cleaned = true;
        });

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Test exception');

        try {
            throw new RuntimeException('Test exception');
        } finally {
            $scope->close();
            // Verify cleanup happened even though we're about to propagate exception
            /** @var bool $cleanedFinal */
            $cleanedFinal = $cleaned;
            self::assertTrue($cleanedFinal);
        }
    }

    #[Test]
    public function callbackCanBeEmptyClosure(): void
    {
        $scope = new ContextScope(function (): void {
            // Empty callback
        });

        // Should not throw
        $scope->close();

        // If we reach here, no exception was thrown
        self::assertInstanceOf(ContextScope::class, $scope);
    }

    #[Test]
    public function scopeDoesNotInvokeCallbackUntilClose(): void
    {
        $executed = false;

        $scope = new ContextScope(function () use (&$executed): void {
            $executed = true;
        });

        self::assertFalse($executed);

        // Callback should not be called yet
        unset($scope); // Even when scope goes out of scope (destructor not implemented)

        // In a real RAII implementation, you might want destructor behavior,
        // but ContextScope requires explicit close()
    }

    #[Test]
    public function nestedScopesWorkCorrectly(): void
    {
        /** @var list<string> $log */
        $log = [];

        $outer = new ContextScope(function () use (&$log): void {
            $log[] = 'outer-closed';
        });

        $inner = new ContextScope(function () use (&$log): void {
            $log[] = 'inner-closed';
        });

        $inner->close();
        /** @var list<string> $logAfterInner */
        $logAfterInner = $log;
        self::assertSame(['inner-closed'], $logAfterInner);

        $outer->close();
        /** @var list<string> $logAfterOuter */
        $logAfterOuter = $log;
        self::assertSame(['inner-closed', 'outer-closed'], $logAfterOuter);
    }

    #[Test]
    public function callbackCanAccessClosureVariables(): void
    {
        /** @var string $value */
        $value = 'initial';

        $scope = new ContextScope(function () use (&$value): void {
            $value = 'modified';
        });

        /** @var string $valueBefore */
        $valueBefore = $value;
        self::assertSame('initial', $valueBefore);

        $scope->close();

        /** @var string $valueAfter */
        $valueAfter = $value;
        self::assertSame('modified', $valueAfter);
    }
}
