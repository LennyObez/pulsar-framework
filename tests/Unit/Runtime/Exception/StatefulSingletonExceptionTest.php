<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Runtime\Exception;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Runtime\Exception\StatefulSingletonException;
use Pulsar\Runtime\Scope\StatefulSingletonViolation;
use Pulsar\Runtime\Scope\ViolationType;
use RuntimeException;

#[CoversClass(StatefulSingletonException::class)]
final class StatefulSingletonExceptionTest extends TestCase
{
    #[Test]
    public function it_extends_runtime_exception(): void
    {
        $violation = new StatefulSingletonViolation(
            className: 'Pulsar\\Service',
            property: 'state',
            type: ViolationType::WritableProperty,
            message: 'Writable property detected',
        );

        $exception = StatefulSingletonException::detected([$violation]);

        self::assertInstanceOf(RuntimeException::class, $exception);
    }

    #[Test]
    public function detected_with_single_violation(): void
    {
        $violation = new StatefulSingletonViolation(
            className: 'Pulsar\\Cache\\Store',
            property: 'items',
            type: ViolationType::WritableProperty,
            message: 'Singleton Pulsar\\Cache\\Store has writable property $items',
        );

        $exception = StatefulSingletonException::detected([$violation]);

        self::assertStringContainsString('1 stateful singleton violation', $exception->getMessage());
        self::assertStringContainsString('Pulsar\\Cache\\Store', $exception->getMessage());
        self::assertStringContainsString('writable property $items', $exception->getMessage());
        self::assertStringContainsString('strict mode', $exception->getMessage());
    }

    #[Test]
    public function detected_with_multiple_violations(): void
    {
        $violations = [
            new StatefulSingletonViolation(
                className: 'Pulsar\\Cache\\Store',
                property: 'items',
                type: ViolationType::WritableProperty,
                message: 'Singleton Pulsar\\Cache\\Store has writable property $items',
            ),
            new StatefulSingletonViolation(
                className: 'Pulsar\\Counter\\Service',
                property: 'count',
                type: ViolationType::MutableStatic,
                message: 'Singleton Pulsar\\Counter\\Service has mutable static property $count',
            ),
            new StatefulSingletonViolation(
                className: 'Pulsar\\Session\\Manager',
                property: 'reset()',
                type: ViolationType::ResetMethod,
                message: 'Singleton Pulsar\\Session\\Manager has reset() method',
            ),
        ];

        $exception = StatefulSingletonException::detected($violations);

        self::assertStringContainsString('3 stateful singleton violation', $exception->getMessage());
        self::assertStringContainsString('Pulsar\\Cache\\Store', $exception->getMessage());
        self::assertStringContainsString('Pulsar\\Counter\\Service', $exception->getMessage());
        self::assertStringContainsString('Pulsar\\Session\\Manager', $exception->getMessage());
    }
}
