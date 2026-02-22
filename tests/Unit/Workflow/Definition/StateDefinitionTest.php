<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Workflow\Definition;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Workflow\Definition\StateDefinition;
use Pulsar\Workflow\Definition\StateType;

#[CoversClass(StateDefinition::class)]
#[CoversClass(StateType::class)]
final class StateDefinitionTest extends TestCase
{
    #[Test]
    public function test_constructor_defaults_to_intermediate(): void
    {
        $state = new StateDefinition('middle');

        self::assertSame('middle', $state->name);
        self::assertSame(StateType::Intermediate, $state->type);
        self::assertSame([], $state->metadata);
    }

    #[Test]
    public function test_initial_factory_creates_initial_state(): void
    {
        $state = StateDefinition::initial('start');

        self::assertSame('start', $state->name);
        self::assertSame(StateType::Initial, $state->type);
        self::assertTrue($state->isInitial());
        self::assertFalse($state->isFinal());
    }

    #[Test]
    public function test_intermediate_factory_creates_intermediate_state(): void
    {
        $state = StateDefinition::intermediate('processing');

        self::assertSame('processing', $state->name);
        self::assertSame(StateType::Intermediate, $state->type);
        self::assertFalse($state->isInitial());
        self::assertFalse($state->isFinal());
    }

    #[Test]
    public function test_final_factory_creates_final_state(): void
    {
        $state = StateDefinition::final('completed');

        self::assertSame('completed', $state->name);
        self::assertSame(StateType::Final, $state->type);
        self::assertFalse($state->isInitial());
        self::assertTrue($state->isFinal());
    }

    #[Test]
    public function test_metadata_is_stored(): void
    {
        $state = StateDefinition::initial('start', ['label' => 'Begin', 'sla' => 30]);

        self::assertSame(['label' => 'Begin', 'sla' => 30], $state->metadata);
    }

    #[Test]
    public function test_metadata_defaults_to_empty_array(): void
    {
        $state = StateDefinition::initial('start');

        self::assertSame([], $state->metadata);
    }

    #[Test]
    public function test_state_type_values(): void
    {
        self::assertSame('initial', StateType::Initial->value);
        self::assertSame('intermediate', StateType::Intermediate->value);
        self::assertSame('final', StateType::Final->value);
    }
}
