<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Console\Exception;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Console\Exception\CommandNotFoundException;
use Pulsar\Console\Exception\ConsoleException;

#[CoversClass(CommandNotFoundException::class)]
final class CommandNotFoundExceptionTest extends TestCase
{
    #[Test]
    public function forCommandIncludesName(): void
    {
        $exception = CommandNotFoundException::forCommand('migrate:rollback');

        self::assertStringContainsString('migrate:rollback', $exception->getMessage());
        self::assertStringContainsString('not found', $exception->getMessage());
    }

    #[Test]
    public function forCommandWithAlternativesSuggestsThem(): void
    {
        $exception = CommandNotFoundException::forCommand(
            'migrat:run',
            ['migrate:run', 'migrate:rollback'],
        );

        self::assertStringContainsString('migrate:run', $exception->getMessage());
        self::assertStringContainsString('migrate:rollback', $exception->getMessage());
        self::assertStringContainsString('Did you mean', $exception->getMessage());
    }

    #[Test]
    public function forCommandWithoutAlternativesOmitsSuggestion(): void
    {
        $exception = CommandNotFoundException::forCommand('nonexistent');

        self::assertStringNotContainsString('Did you mean', $exception->getMessage());
    }

    #[Test]
    public function ambiguousIncludesAllMatches(): void
    {
        $exception = CommandNotFoundException::ambiguous(
            'cache',
            ['cache:clear', 'cache:warmup', 'cache:status'],
        );

        self::assertStringContainsString('ambiguous', $exception->getMessage());
        self::assertStringContainsString('cache:clear', $exception->getMessage());
        self::assertStringContainsString('cache:warmup', $exception->getMessage());
        self::assertStringContainsString('cache:status', $exception->getMessage());
    }

    #[Test]
    public function extendsConsoleException(): void
    {
        $exception = CommandNotFoundException::forCommand('unknown');

        self::assertInstanceOf(ConsoleException::class, $exception);
    }
}
