<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Workflow\Definition;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Workflow\Definition\TransitionDefinition;
use Pulsar\Workflow\Guard\RoleGuard;

#[CoversClass(TransitionDefinition::class)]
final class TransitionDefinitionTest extends TestCase
{
    #[Test]
    public function test_constructor_stores_all_fields(): void
    {
        $transition = new TransitionDefinition(
            name: 'submit',
            froms: ['draft', 'returned'],
            to: 'review',
            guards: [RoleGuard::class],
            metadata: ['priority' => 'high'],
        );

        self::assertSame('submit', $transition->name);
        self::assertSame(['draft', 'returned'], $transition->froms);
        self::assertSame('review', $transition->to);
        self::assertSame([RoleGuard::class], $transition->guards);
        self::assertSame(['priority' => 'high'], $transition->metadata);
    }

    #[Test]
    public function test_create_factory_wraps_single_from_in_array(): void
    {
        $transition = TransitionDefinition::create('go', 'start', 'end');

        self::assertSame(['start'], $transition->froms);
    }

    #[Test]
    public function test_create_factory_sets_name_and_target(): void
    {
        $transition = TransitionDefinition::create('go', 'start', 'end');

        self::assertSame('go', $transition->name);
        self::assertSame('end', $transition->to);
    }

    #[Test]
    public function test_create_factory_with_guards_and_metadata(): void
    {
        $transition = TransitionDefinition::create(
            name: 'approve',
            from: 'review',
            to: 'approved',
            guards: [RoleGuard::class],
            metadata: ['required_roles' => ['admin']],
        );

        self::assertSame([RoleGuard::class], $transition->guards);
        self::assertSame(['required_roles' => ['admin']], $transition->metadata);
    }

    #[Test]
    public function test_guards_default_to_empty(): void
    {
        $transition = TransitionDefinition::create('go', 'a', 'b');

        self::assertSame([], $transition->guards);
    }

    #[Test]
    public function test_metadata_defaults_to_empty(): void
    {
        $transition = TransitionDefinition::create('go', 'a', 'b');

        self::assertSame([], $transition->metadata);
    }

    #[Test]
    public function test_can_transition_from_returns_true_for_matching_state(): void
    {
        $transition = new TransitionDefinition(
            name: 'merge',
            froms: ['branch_a', 'branch_b'],
            to: 'joined',
        );

        self::assertTrue($transition->canTransitionFrom('branch_a'));
        self::assertTrue($transition->canTransitionFrom('branch_b'));
    }

    #[Test]
    public function test_can_transition_from_returns_false_for_non_matching_state(): void
    {
        $transition = TransitionDefinition::create('go', 'start', 'end');

        self::assertFalse($transition->canTransitionFrom('end'));
        self::assertFalse($transition->canTransitionFrom('other'));
    }

    #[Test]
    public function test_can_transition_from_uses_strict_comparison(): void
    {
        $transition = TransitionDefinition::create('go', 'state_1', 'end');

        self::assertFalse($transition->canTransitionFrom('state_10'));
        self::assertFalse($transition->canTransitionFrom('STATE_1'));
    }
}
