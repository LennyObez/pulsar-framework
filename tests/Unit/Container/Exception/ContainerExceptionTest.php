<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Container\Exception;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerExceptionInterface;
use Pulsar\Container\Exception\ContainerException;

#[CoversClass(ContainerException::class)]
final class ContainerExceptionTest extends TestCase
{
    #[Test]
    public function implementsPsrContainerExceptionInterface(): void
    {
        $exception = ContainerException::unresolvable('foo');

        self::assertInstanceOf(ContainerExceptionInterface::class, $exception);
    }

    #[Test]
    public function unresolvableWithoutReason(): void
    {
        $exception = ContainerException::unresolvable('App\\Service');

        self::assertSame('Unable to resolve binding "App\\Service"', $exception->getMessage());
    }

    #[Test]
    public function unresolvableWithReason(): void
    {
        $exception = ContainerException::unresolvable('App\\Service', 'class not found');

        self::assertSame(
            'Unable to resolve binding "App\\Service": class not found',
            $exception->getMessage(),
        );
    }

    #[Test]
    public function unresolvableWithEmptyReason(): void
    {
        $exception = ContainerException::unresolvable('Foo', '');

        self::assertStringNotContainsString(':', $exception->getMessage());
    }

    #[Test]
    public function circularDependencyIncludesChain(): void
    {
        $exception = ContainerException::circularDependency('C', ['A', 'B', 'C']);

        $message = $exception->getMessage();

        self::assertStringContainsString('Circular dependency', $message);
        self::assertStringContainsString('A -> B -> C -> C', $message);
    }

    #[Test]
    public function circularDependencyWithSingleElementChain(): void
    {
        $exception = ContainerException::circularDependency('A', ['A']);

        self::assertStringContainsString('A -> A', $exception->getMessage());
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function circularDependencyProvider(): iterable
    {
        yield 'two-element cycle' => ['B', ['A', 'B']];
        yield 'three-element cycle' => ['C', ['A', 'B', 'C']];
        yield 'self-referencing' => ['X', ['X']];
    }

    /**
     * @param list<string> $chain
     */
    #[Test]
    #[DataProvider('circularDependencyProvider')]
    public function circularDependencyFormatsMessageCorrectly(string $id, array $chain): void
    {
        $exception = ContainerException::circularDependency($id, $chain);

        self::assertStringContainsString('Circular dependency detected while resolving', $exception->getMessage());
        self::assertStringContainsString('"' . $id . '"', $exception->getMessage());
    }
}
