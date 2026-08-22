<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Workflow\Guard;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Workflow\ActorContext;
use Pulsar\Workflow\Definition\TransitionDefinition;
use Pulsar\Workflow\Guard\RoleGuard;
use Pulsar\Workflow\Storage\ClassifiedContext;
use Pulsar\Workflow\Storage\WorkflowInstance;
use Pulsar\Workflow\Storage\WorkflowInstanceStatus;

final class RoleGuardTest extends TestCase
{
    private RoleGuard $guard;

    protected function setUp(): void
    {
        $this->guard = new RoleGuard();
    }

    #[Test]
    public function allows_transition_with_no_required_roles(): void
    {
        $actor = new ActorContext(subjectId: 'user-1');
        $transition = TransitionDefinition::create('submit', 'draft', 'review');
        $instance = $this->createInstance([]);

        $result = $this->guard->evaluate($actor, $transition, $instance);

        self::assertTrue($result->isAllowed());
    }

    #[Test]
    public function allows_transition_when_actor_has_required_role(): void
    {
        $actor = new ActorContext(subjectId: 'user-1');
        $transition = TransitionDefinition::create(
            name: 'approve',
            from: 'review',
            to: 'approved',
            metadata: ['required_roles' => ['admin', 'manager']],
        );
        $instance = $this->createInstance(['actor_roles' => ['manager']]);

        $result = $this->guard->evaluate($actor, $transition, $instance);

        self::assertTrue($result->isAllowed());
    }

    #[Test]
    public function denies_transition_when_actor_lacks_required_roles(): void
    {
        $actor = new ActorContext(subjectId: 'user-1');
        $transition = TransitionDefinition::create(
            name: 'approve',
            from: 'review',
            to: 'approved',
            metadata: ['required_roles' => ['admin']],
        );
        $instance = $this->createInstance(['actor_roles' => ['viewer']]);

        $result = $this->guard->evaluate($actor, $transition, $instance);

        self::assertTrue($result->isDenied());
        self::assertStringContainsString('user-1', $result->reason);
        self::assertStringContainsString('admin', $result->reason);
    }

    #[Test]
    public function denies_when_actor_has_no_roles_in_context(): void
    {
        $actor = new ActorContext(subjectId: 'user-1');
        $transition = TransitionDefinition::create(
            name: 'approve',
            from: 'review',
            to: 'approved',
            metadata: ['required_roles' => ['admin']],
        );
        $instance = $this->createInstance([]);

        $result = $this->guard->evaluate($actor, $transition, $instance);

        self::assertTrue($result->isDenied());
    }

    #[Test]
    public function allows_when_actor_has_one_of_multiple_required_roles(): void
    {
        $actor = new ActorContext(subjectId: 'user-1');
        $transition = TransitionDefinition::create(
            name: 'approve',
            from: 'review',
            to: 'approved',
            metadata: ['required_roles' => ['admin', 'manager', 'supervisor']],
        );
        $instance = $this->createInstance(['actor_roles' => ['supervisor', 'viewer']]);

        $result = $this->guard->evaluate($actor, $transition, $instance);

        self::assertTrue($result->isAllowed());
    }

    /**
     * @param array<string, mixed> $contextValues
     */
    private function createInstance(array $contextValues): WorkflowInstance
    {
        $context = new ClassifiedContext();

        foreach ($contextValues as $key => $value) {
            $context = $context->set(
                $key,
                $value,
                \Pulsar\Workflow\Storage\ClassificationLevel::Internal,
            );
        }

        return new WorkflowInstance(
            id: 'wf-1',
            definitionId: 'test-workflow',
            definitionVersion: 1,
            currentState: 'review',
            context: $context,
            version: 1,
            status: WorkflowInstanceStatus::Active,
            startedAt: new DateTimeImmutable(),
            completedAt: null,
            startedBy: 'user-1',
        );
    }
}
