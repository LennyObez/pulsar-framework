<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extension\Studio;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extension\Studio\ContextScope;
use stdClass;

#[CoversClass(ContextScope::class)]
final class ContextScopeTest extends TestCase
{
    #[Test]
    public function closeInvokesCallback(): void
    {
        $state = new stdClass();
        $state->called = false;
        $scope = new ContextScope(static function () use ($state): void {
            $state->called = true;
        });

        self::assertFalse($state->called);

        $scope->close();

        self::assertTrue($state->called);
    }

    #[Test]
    public function closeIsIdempotent(): void
    {
        $callCount = 0;
        $scope = new ContextScope(static function () use (&$callCount): void {
            $callCount++;
        });

        $scope->close();
        $scope->close();
        $scope->close();

        self::assertSame(1, $callCount);
    }
}
