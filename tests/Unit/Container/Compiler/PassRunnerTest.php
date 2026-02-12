<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Container\Compiler;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Container\Compiler\CompilerPassInterface;
use Pulsar\Container\Compiler\ContainerBuilder;
use Pulsar\Container\Compiler\PassRunner;

#[CoversClass(PassRunner::class)]
final class PassRunnerTest extends TestCase
{
    #[Test]
    public function runsPassesInDeterministicOrder(): void
    {
        $executionOrder = [];

        $passA = new class ($executionOrder) implements CompilerPassInterface {
            public function __construct(private array &$order) {}

            public function process(ContainerBuilder $builder): void
            {
                $this->order[] = 'A';
            }
        };

        $passB = new class ($executionOrder) implements CompilerPassInterface {
            public function __construct(private array &$order) {}

            public function process(ContainerBuilder $builder): void
            {
                $this->order[] = 'B';
            }
        };

        $runner = new PassRunner();
        // Register in reverse order
        $runner->addPass($passB, 0);
        $runner->addPass($passA, 10);

        $runner->run(new ContainerBuilder());

        // A should run first (higher priority)
        self::assertSame(['A', 'B'], $executionOrder);
    }

    #[Test]
    public function samePrioritySortsByFqcn(): void
    {
        $executionOrder = [];

        $passA = new class ($executionOrder) implements CompilerPassInterface {
            public function __construct(private array &$order) {}

            public function process(ContainerBuilder $builder): void
            {
                $this->order[] = static::class;
            }
        };

        $passB = new class ($executionOrder) implements CompilerPassInterface {
            public function __construct(private array &$order) {}

            public function process(ContainerBuilder $builder): void
            {
                $this->order[] = static::class;
            }
        };

        $runner = new PassRunner();
        $runner->addPass($passB, 0);
        $runner->addPass($passA, 0);

        $runner->run(new ContainerBuilder());

        // Same priority → sorted by FQCN ASC
        self::assertCount(2, $executionOrder);
        self::assertTrue($executionOrder[0] <= $executionOrder[1]);
    }
}
