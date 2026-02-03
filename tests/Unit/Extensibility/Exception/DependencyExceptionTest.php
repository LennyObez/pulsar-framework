<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Extensibility\Exception;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Extensibility\Exception\DependencyException;
use Pulsar\Extensibility\Exception\ExtensionException;
use RuntimeException;

#[CoversClass(DependencyException::class)]
final class DependencyExceptionTest extends TestCase
{
    #[Test]
    public function extendsExtensionException(): void
    {
        $exception = DependencyException::unresolvable('conflict');

        self::assertInstanceOf(ExtensionException::class, $exception);
        self::assertInstanceOf(RuntimeException::class, $exception);
    }

    #[Test]
    public function missingDependencyContainsExtensionAndDependency(): void
    {
        $exception = DependencyException::missingDependency('acme/billing', 'acme/core');

        self::assertSame(
            'Extension "acme/billing" requires extension "acme/core" which is not available',
            $exception->getMessage(),
        );
    }

    #[Test]
    public function versionMismatchContainsAllDetails(): void
    {
        $exception = DependencyException::versionMismatch(
            'acme/billing',
            'acme/core',
            '^2.0',
            '1.5.0',
        );

        self::assertSame(
            'Extension "acme/billing" requires "acme/core" version ^2.0, but version 1.5.0 is available',
            $exception->getMessage(),
        );
    }

    #[Test]
    public function circularDependencyContainsChain(): void
    {
        $exception = DependencyException::circularDependency(['A', 'B', 'C', 'A']);

        self::assertSame(
            'Circular dependency detected: A -> B -> C -> A',
            $exception->getMessage(),
        );
    }

    #[Test]
    public function circularDependencyWithTwoElements(): void
    {
        $exception = DependencyException::circularDependency(['X', 'X']);

        self::assertSame(
            'Circular dependency detected: X -> X',
            $exception->getMessage(),
        );
    }

    #[Test]
    public function unresolvableContainsReason(): void
    {
        $exception = DependencyException::unresolvable('conflicting version constraints');

        self::assertSame(
            'Unable to resolve extension dependencies: conflicting version constraints',
            $exception->getMessage(),
        );
    }
}
