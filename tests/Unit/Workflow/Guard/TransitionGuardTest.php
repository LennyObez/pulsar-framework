<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Workflow\Guard;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Workflow\ActorContext;
use Pulsar\Workflow\Definition\TransitionDefinition;
use Pulsar\Workflow\Guard\ExpressionGuard;
use Pulsar\Workflow\Guard\RoleGuard;
use Pulsar\Workflow\Storage\ClassificationLevel;
use Pulsar\Workflow\Storage\ClassifiedContext;
use Pulsar\Workflow\Storage\WorkflowInstance;
use Pulsar\Workflow\Storage\WorkflowInstanceStatus;

#[CoversClass(RoleGuard::class)]
#[CoversClass(ExpressionGuard::class)]
final class TransitionGuardTest extends TestCase
{
    // --- RoleGuard ---

    #[Test]
    public function test_role_guard_allows_when_no_required_roles(): void
    {
        $guard = new RoleGuard();
        $actor = new ActorContext(subjectId: 'user-1');
        $transition = TransitionDefinition::create('go', 'a', 'b');
        $instance = $this->createInstance();

        $result = $guard->evaluate($actor, $transition, $instance);

        self::assertTrue($result->isAllowed());
    }

    #[Test]
    public function test_role_guard_allows_when_actor_has_required_role(): void
    {
        $guard = new RoleGuard();
        $actor = new ActorContext(subjectId: 'user-1');
        $transition = TransitionDefinition::create(
            'approve',
            'review',
            'approved',
            metadata: ['required_roles' => ['reviewer']],
        );
        $context = new ClassifiedContext()
            ->set('actor_roles', ['reviewer', 'editor'], ClassificationLevel::Internal);
        $instance = $this->createInstance(context: $context);

        $result = $guard->evaluate($actor, $transition, $instance);

        self::assertTrue($result->isAllowed());
    }

    #[Test]
    public function test_role_guard_denies_when_actor_lacks_role(): void
    {
        $guard = new RoleGuard();
        $actor = new ActorContext(subjectId: 'user-42');
        $transition = TransitionDefinition::create(
            'approve',
            'review',
            'approved',
            metadata: ['required_roles' => ['admin']],
        );
        $context = new ClassifiedContext()
            ->set('actor_roles', ['viewer'], ClassificationLevel::Internal);
        $instance = $this->createInstance(context: $context);

        $result = $guard->evaluate($actor, $transition, $instance);

        self::assertTrue($result->isDenied());
        self::assertStringContainsString('user-42', $result->reason);
        self::assertStringContainsString('admin', $result->reason);
    }

    #[Test]
    public function test_role_guard_denies_when_no_actor_roles_in_context(): void
    {
        $guard = new RoleGuard();
        $actor = new ActorContext(subjectId: 'user-1');
        $transition = TransitionDefinition::create(
            'approve',
            'review',
            'approved',
            metadata: ['required_roles' => ['reviewer']],
        );
        $instance = $this->createInstance();

        $result = $guard->evaluate($actor, $transition, $instance);

        self::assertTrue($result->isDenied());
    }

    // --- ExpressionGuard ---

    #[Test]
    public function test_expression_guard_allows_when_no_expression(): void
    {
        $guard = new ExpressionGuard();
        $actor = new ActorContext(subjectId: 'user-1');
        $transition = TransitionDefinition::create('go', 'a', 'b');
        $instance = $this->createInstance();

        $result = $guard->evaluate($actor, $transition, $instance);

        self::assertTrue($result->isAllowed());
    }

    #[Test]
    public function test_expression_guard_allows_when_empty_expression(): void
    {
        $guard = new ExpressionGuard();
        $actor = new ActorContext(subjectId: 'user-1');
        $transition = TransitionDefinition::create(
            'go',
            'a',
            'b',
            metadata: ['guard_expression' => ''],
        );
        $instance = $this->createInstance();

        $result = $guard->evaluate($actor, $transition, $instance);

        self::assertTrue($result->isAllowed());
    }

    #[Test]
    public function test_expression_guard_has_allows_when_field_exists(): void
    {
        $guard = new ExpressionGuard();
        $actor = new ActorContext(subjectId: 'user-1');
        $transition = TransitionDefinition::create(
            'go',
            'a',
            'b',
            metadata: ['guard_expression' => 'context:has:review_comment'],
        );
        $context = new ClassifiedContext()
            ->set('review_comment', 'Looks good', ClassificationLevel::Internal);
        $instance = $this->createInstance(context: $context);

        $result = $guard->evaluate($actor, $transition, $instance);

        self::assertTrue($result->isAllowed());
    }

    #[Test]
    public function test_expression_guard_has_denies_when_field_missing(): void
    {
        $guard = new ExpressionGuard();
        $actor = new ActorContext(subjectId: 'user-1');
        $transition = TransitionDefinition::create(
            'go',
            'a',
            'b',
            metadata: ['guard_expression' => 'context:has:review_comment'],
        );
        $instance = $this->createInstance();

        $result = $guard->evaluate($actor, $transition, $instance);

        self::assertTrue($result->isDenied());
        self::assertStringContainsString('review_comment', $result->reason);
    }

    #[Test]
    public function test_expression_guard_eq_allows_when_equal(): void
    {
        $guard = new ExpressionGuard();
        $actor = new ActorContext(subjectId: 'user-1');
        $transition = TransitionDefinition::create(
            'go',
            'a',
            'b',
            metadata: ['guard_expression' => 'context:eq:status:ready'],
        );
        $context = new ClassifiedContext()
            ->set('status', 'ready', ClassificationLevel::Internal);
        $instance = $this->createInstance(context: $context);

        $result = $guard->evaluate($actor, $transition, $instance);

        self::assertTrue($result->isAllowed());
    }

    #[Test]
    public function test_expression_guard_eq_denies_when_not_equal(): void
    {
        $guard = new ExpressionGuard();
        $actor = new ActorContext(subjectId: 'user-1');
        $transition = TransitionDefinition::create(
            'go',
            'a',
            'b',
            metadata: ['guard_expression' => 'context:eq:status:ready'],
        );
        $context = new ClassifiedContext()
            ->set('status', 'pending', ClassificationLevel::Internal);
        $instance = $this->createInstance(context: $context);

        $result = $guard->evaluate($actor, $transition, $instance);

        self::assertTrue($result->isDenied());
        self::assertStringContainsString('equal', $result->reason);
    }

    #[Test]
    public function test_expression_guard_neq_allows_when_not_equal(): void
    {
        $guard = new ExpressionGuard();
        $actor = new ActorContext(subjectId: 'user-1');
        $transition = TransitionDefinition::create(
            'go',
            'a',
            'b',
            metadata: ['guard_expression' => 'context:neq:status:blocked'],
        );
        $context = new ClassifiedContext()
            ->set('status', 'active', ClassificationLevel::Internal);
        $instance = $this->createInstance(context: $context);

        $result = $guard->evaluate($actor, $transition, $instance);

        self::assertTrue($result->isAllowed());
    }

    #[Test]
    public function test_expression_guard_neq_denies_when_equal(): void
    {
        $guard = new ExpressionGuard();
        $actor = new ActorContext(subjectId: 'user-1');
        $transition = TransitionDefinition::create(
            'go',
            'a',
            'b',
            metadata: ['guard_expression' => 'context:neq:status:blocked'],
        );
        $context = new ClassifiedContext()
            ->set('status', 'blocked', ClassificationLevel::Internal);
        $instance = $this->createInstance(context: $context);

        $result = $guard->evaluate($actor, $transition, $instance);

        self::assertTrue($result->isDenied());
        self::assertStringContainsString('not equal', $result->reason);
    }

    #[Test]
    public function test_expression_guard_denies_unknown_domain(): void
    {
        $guard = new ExpressionGuard();
        $actor = new ActorContext(subjectId: 'user-1');
        $transition = TransitionDefinition::create(
            'go',
            'a',
            'b',
            metadata: ['guard_expression' => 'unknown:has:field'],
        );
        $instance = $this->createInstance();

        $result = $guard->evaluate($actor, $transition, $instance);

        self::assertTrue($result->isDenied());
        self::assertStringContainsString('Unknown expression domain', $result->reason);
    }

    #[Test]
    public function test_expression_guard_denies_unknown_operator(): void
    {
        $guard = new ExpressionGuard();
        $actor = new ActorContext(subjectId: 'user-1');
        $transition = TransitionDefinition::create(
            'go',
            'a',
            'b',
            metadata: ['guard_expression' => 'context:gt:amount:100'],
        );
        $instance = $this->createInstance();

        $result = $guard->evaluate($actor, $transition, $instance);

        self::assertTrue($result->isDenied());
        self::assertStringContainsString('Unknown expression operator', $result->reason);
    }

    #[Test]
    public function test_expression_guard_has_denies_when_field_name_empty(): void
    {
        $guard = new ExpressionGuard();
        $actor = new ActorContext(subjectId: 'user-1');
        $transition = TransitionDefinition::create(
            'go',
            'a',
            'b',
            metadata: ['guard_expression' => 'context:has:'],
        );
        $instance = $this->createInstance();

        $result = $guard->evaluate($actor, $transition, $instance);

        self::assertTrue($result->isDenied());
        self::assertStringContainsString('requires a field name', $result->reason);
    }

    #[Test]
    public function test_expression_guard_eq_denies_when_field_name_empty(): void
    {
        $guard = new ExpressionGuard();
        $actor = new ActorContext(subjectId: 'user-1');
        $transition = TransitionDefinition::create(
            'go',
            'a',
            'b',
            metadata: ['guard_expression' => 'context:eq::value'],
        );
        $instance = $this->createInstance();

        $result = $guard->evaluate($actor, $transition, $instance);

        self::assertTrue($result->isDenied());
        self::assertStringContainsString('requires a field name and value', $result->reason);
    }

    #[Test]
    public function test_expression_guard_eq_denies_when_field_not_in_context(): void
    {
        $guard = new ExpressionGuard();
        $actor = new ActorContext(subjectId: 'user-1');
        $transition = TransitionDefinition::create(
            'go',
            'a',
            'b',
            metadata: ['guard_expression' => 'context:eq:missing_field:value'],
        );
        $instance = $this->createInstance();

        $result = $guard->evaluate($actor, $transition, $instance);

        self::assertTrue($result->isDenied());
        self::assertStringContainsString('does not exist', $result->reason);
    }

    private function createInstance(?ClassifiedContext $context = null): WorkflowInstance
    {
        return new WorkflowInstance(
            id: 'inst-1',
            definitionId: 'test',
            definitionVersion: 1,
            currentState: 'review',
            context: $context ?? new ClassifiedContext(),
            version: 1,
            status: WorkflowInstanceStatus::Active,
            startedAt: new DateTimeImmutable(),
            completedAt: null,
            startedBy: 'user-1',
        );
    }
}
