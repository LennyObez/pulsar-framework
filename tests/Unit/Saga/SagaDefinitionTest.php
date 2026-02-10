<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Saga;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Saga\Exception\SagaException;
use Pulsar\Saga\SagaDefinition;
use Pulsar\Saga\Step\SagaStep;
use stdClass;

use function array_keys;

#[CoversClass(SagaDefinition::class)]
final class SagaDefinitionTest extends TestCase
{
    #[Test]
    public function test_construction_with_steps(): void
    {
        $steps = [
            'step_a' => new SagaStep(name: 'step_a', forwardAction: stdClass::class),
            'step_b' => new SagaStep(name: 'step_b', forwardAction: stdClass::class),
        ];

        $definition = new SagaDefinition(name: 'test_saga', steps: $steps);

        self::assertSame('test_saga', $definition->name);
        self::assertSame($steps, $definition->steps);
        self::assertSame([], $definition->metadata);
    }

    #[Test]
    public function test_construction_with_metadata(): void
    {
        $metadata = ['domain' => 'payments', 'version' => '2.0'];

        $definition = new SagaDefinition(
            name: 'test_saga',
            steps: ['s' => new SagaStep(name: 's', forwardAction: stdClass::class)],
            metadata: $metadata,
        );

        self::assertSame($metadata, $definition->metadata);
    }

    #[Test]
    public function test_stepCount(): void
    {
        $steps = [
            'a' => new SagaStep(name: 'a', forwardAction: stdClass::class),
            'b' => new SagaStep(name: 'b', forwardAction: stdClass::class),
            'c' => new SagaStep(name: 'c', forwardAction: stdClass::class),
        ];

        $definition = new SagaDefinition(name: 'test', steps: $steps);

        self::assertSame(3, $definition->stepCount());
    }

    #[Test]
    public function test_stepCount_empty(): void
    {
        $definition = new SagaDefinition(name: 'test', steps: []);

        self::assertSame(0, $definition->stepCount());
    }

    #[Test]
    public function test_getStep_existing(): void
    {
        $step = new SagaStep(name: 'charge', forwardAction: stdClass::class);
        $definition = new SagaDefinition(name: 'test', steps: ['charge' => $step]);

        self::assertSame($step, $definition->getStep('charge'));
    }

    #[Test]
    public function test_getStep_nonexistent_throws(): void
    {
        $definition = new SagaDefinition(
            name: 'test',
            steps: ['charge' => new SagaStep(name: 'charge', forwardAction: stdClass::class)],
        );

        $this->expectException(SagaException::class);
        $this->expectExceptionMessage('step not found in definition');

        $definition->getStep('nonexistent');
    }

    #[Test]
    public function test_getStepAtIndex_valid(): void
    {
        $stepA = new SagaStep(name: 'a', forwardAction: stdClass::class);
        $stepB = new SagaStep(name: 'b', forwardAction: stdClass::class);

        $definition = new SagaDefinition(name: 'test', steps: ['a' => $stepA, 'b' => $stepB]);

        self::assertSame($stepA, $definition->getStepAtIndex(0));
        self::assertSame($stepB, $definition->getStepAtIndex(1));
    }

    #[Test]
    public function test_getStepAtIndex_out_of_bounds_throws(): void
    {
        $definition = new SagaDefinition(
            name: 'test',
            steps: ['a' => new SagaStep(name: 'a', forwardAction: stdClass::class)],
        );

        $this->expectException(SagaException::class);
        $this->expectExceptionMessage('step index out of bounds');

        $definition->getStepAtIndex(5);
    }

    #[Test]
    public function test_getStepNames_returns_ordered_names(): void
    {
        $steps = [
            'charge' => new SagaStep(name: 'charge', forwardAction: stdClass::class),
            'reserve' => new SagaStep(name: 'reserve', forwardAction: stdClass::class),
            'confirm' => new SagaStep(name: 'confirm', forwardAction: stdClass::class),
        ];

        $definition = new SagaDefinition(name: 'test', steps: $steps);

        self::assertSame(['charge', 'reserve', 'confirm'], $definition->getStepNames());
    }

    #[Test]
    public function test_validate_passes_with_steps(): void
    {
        $definition = new SagaDefinition(
            name: 'test',
            steps: ['s' => new SagaStep(name: 's', forwardAction: stdClass::class)],
        );

        $definition->validate();

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function test_validate_throws_when_no_steps(): void
    {
        $definition = new SagaDefinition(name: 'empty_saga', steps: []);

        $this->expectException(SagaException::class);
        $this->expectExceptionMessage('has no steps defined');

        $definition->validate();
    }

    #[Test]
    public function test_steps_preserve_insertion_order(): void
    {
        $steps = [
            'third' => new SagaStep(name: 'third', forwardAction: stdClass::class),
            'first' => new SagaStep(name: 'first', forwardAction: stdClass::class),
            'second' => new SagaStep(name: 'second', forwardAction: stdClass::class),
        ];

        $definition = new SagaDefinition(name: 'test', steps: $steps);

        self::assertSame(['third', 'first', 'second'], array_keys($definition->steps));
    }
}
