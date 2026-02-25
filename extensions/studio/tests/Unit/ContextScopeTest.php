<?php

declare(strict_types=1);

namespace Pulsar\Extension\Studio\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Studio\ContextScope;

final class ContextScopeTest extends TestCase
{
    #[Test]
    public function closeInvokesCallback(): void
    {
        $called = false;
        $scope = new ContextScope(function () use (&$called): void {
            $called = true;
        });

        $scope->close();

        self::assertTrue($called);
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
}
