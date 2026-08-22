<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Cache\Application\Lock;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Cache\Application\Exception\FenceTokenMismatchException;
use Pulsar\Cache\Application\Lock\ArrayLock;
use Pulsar\Cache\Application\Lock\FencedExecutor;
use Pulsar\Cache\Application\Lock\LockHandle;
use Pulsar\Cache\Application\Lock\LockInterface;

#[CoversClass(FencedExecutor::class)]
final class FencedExecutorExtendedTest extends TestCase
{
    #[Test]
    public function executeRunsCallbackWhenLockIsValid(): void
    {
        $lock = new ArrayLock();
        $handle = $lock->acquire('resource-1', 30);
        $executor = new FencedExecutor($lock);

        $result = $executor->execute($handle, static fn(LockHandle $h): string => 'result-' . $h->resource);

        self::assertSame('result-resource-1', $result);
    }

    #[Test]
    public function executeThrowsWhenLockHasExpired(): void
    {
        $lock = $this->createStub(LockInterface::class);
        $lock->method('refresh')->willReturn(false);

        $executor = new FencedExecutor($lock);
        $handle = new LockHandle('expired-resource', 'token-123', microtime(true), 30);

        $this->expectException(FenceTokenMismatchException::class);
        $this->expectExceptionMessageIsOrContains('expired-resource');

        $executor->execute($handle, static fn(): string => 'should not execute');
    }

    #[Test]
    public function executePassesHandleToCallback(): void
    {
        $lock = new ArrayLock();
        $handle = $lock->acquire('test-resource', 30);
        $executor = new FencedExecutor($lock);

        $capturedHandle = null;
        $executor->execute($handle, static function (LockHandle $h) use (&$capturedHandle): void {
            $capturedHandle = $h;
        });

        self::assertSame($handle, $capturedHandle);
    }

    #[Test]
    public function executeReturnsCallbackReturnValue(): void
    {
        $lock = new ArrayLock();
        $handle = $lock->acquire('data-resource', 30);
        $executor = new FencedExecutor($lock);

        $result = $executor->execute($handle, static fn(): array => ['status' => 'ok', 'count' => 42]);

        self::assertSame(['status' => 'ok', 'count' => 42], $result);
    }

    #[Test]
    public function executeRefreshesLockBeforeCallback(): void
    {
        $lock = $this->createMock(LockInterface::class);
        $lock->expects(self::once())
            ->method('refresh')
            ->willReturn(true);

        $executor = new FencedExecutor($lock);
        $handle = new LockHandle('res', 'token', microtime(true), 60);

        $executor->execute($handle, static fn(): bool => true);
    }
}
