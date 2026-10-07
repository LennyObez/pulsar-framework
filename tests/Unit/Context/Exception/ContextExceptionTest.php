<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Context\Exception;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Context\Exception\ContextException;
use RuntimeException;

#[CoversClass(ContextException::class)]
final class ContextExceptionTest extends TestCase
{
    #[Test]
    public function extendsRuntimeException(): void
    {
        $exception = ContextException::notAvailable();

        self::assertInstanceOf(RuntimeException::class, $exception);
    }

    #[Test]
    public function notAvailableMessage(): void
    {
        $exception = ContextException::notAvailable();

        self::assertSame(
            'Request context is not available in the current scope',
            $exception->getMessage(),
        );
    }

    #[Test]
    public function invalidCorrelationIdIncludesValue(): void
    {
        $exception = ContextException::invalidCorrelationId('short');

        $message = $exception->getMessage();

        self::assertStringContainsString('Invalid correlation ID', $message);
        self::assertStringContainsString('32 hex characters', $message);
        self::assertStringContainsString('"short"', $message);
    }

    #[Test]
    public function invalidCausationIdIncludesValue(): void
    {
        $exception = ContextException::invalidCausationId('xyz');

        $message = $exception->getMessage();

        self::assertStringContainsString('Invalid causation ID', $message);
        self::assertStringContainsString('32 hex characters', $message);
        self::assertStringContainsString('"xyz"', $message);
    }

    #[Test]
    public function invalidCorrelationIdWithEmptyString(): void
    {
        $exception = ContextException::invalidCorrelationId('');

        self::assertStringContainsString('""', $exception->getMessage());
    }

    #[Test]
    public function invalidCausationIdWithLongString(): void
    {
        $longValue = str_repeat('a', 100);
        $exception = ContextException::invalidCausationId($longValue);

        self::assertStringContainsString($longValue, $exception->getMessage());
    }
}
