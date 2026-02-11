<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Console\Exception;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Console\Exception\ConsoleException;

#[CoversClass(ConsoleException::class)]
final class ConsoleExceptionTest extends TestCase
{
    #[Test]
    public function invalidInputCreatesExceptionWithMessage(): void
    {
        $exception = ConsoleException::invalidInput('Expected integer, got string');

        self::assertSame('Expected integer, got string', $exception->getMessage());
        self::assertInstanceOf(ConsoleException::class, $exception);
    }

    #[Test]
    public function invalidOptionIncludesOptionName(): void
    {
        $exception = ConsoleException::invalidOption('format');

        self::assertStringContainsString('format', $exception->getMessage());
        self::assertStringContainsString('Invalid option', $exception->getMessage());
    }

    #[Test]
    public function missingArgumentIncludesArgumentName(): void
    {
        $exception = ConsoleException::missingArgument('migration-name');

        self::assertStringContainsString('migration-name', $exception->getMessage());
        self::assertStringContainsString('Missing required', $exception->getMessage());
    }
}
