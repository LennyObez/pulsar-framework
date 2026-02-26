<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Console\Repl;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Console\Repl\ReplSafeModeException;
use RuntimeException;

#[CoversClass(ReplSafeModeException::class)]
final class ReplSafeModeExceptionTest extends TestCase
{
    #[Test]
    public function extendsRuntimeException(): void
    {
        $exception = ReplSafeModeException::operationBlocked('test');

        self::assertInstanceOf(RuntimeException::class, $exception);
    }

    #[Test]
    public function operationBlockedContainsOperationName(): void
    {
        $exception = ReplSafeModeException::operationBlocked('execute');

        self::assertStringContainsString('execute', $exception->getMessage());
        self::assertStringContainsString('safe mode', $exception->getMessage());
    }

    #[Test]
    public function writeQueryBlockedContainsSql(): void
    {
        $exception = ReplSafeModeException::writeQueryBlocked('DELETE FROM users');

        self::assertStringContainsString('DELETE FROM users', $exception->getMessage());
        self::assertStringContainsString('safe mode', $exception->getMessage());
    }

    #[Test]
    public function writeQueryBlockedMentionsAllowedQueryTypes(): void
    {
        $exception = ReplSafeModeException::writeQueryBlocked('INSERT INTO logs');

        self::assertStringContainsString('SELECT', $exception->getMessage());
        self::assertStringContainsString('EXPLAIN', $exception->getMessage());
    }
}
