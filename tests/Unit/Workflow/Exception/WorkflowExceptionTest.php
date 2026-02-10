<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Workflow\Exception;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Workflow\Exception\WorkflowException;
use RuntimeException;

#[CoversClass(WorkflowException::class)]
final class WorkflowExceptionTest extends TestCase
{
    #[Test]
    public function test_extends_runtime_exception(): void
    {
        $exception = WorkflowException::definitionNotFound('test');

        self::assertInstanceOf(RuntimeException::class, $exception);
    }

    #[Test]
    public function test_invalid_transition_contains_details(): void
    {
        $exception = WorkflowException::invalidTransition('approve', 'draft', 'order_process');

        self::assertStringContainsString('approve', $exception->getMessage());
        self::assertStringContainsString('draft', $exception->getMessage());
        self::assertStringContainsString('order_process', $exception->getMessage());
    }

    #[Test]
    public function test_invalid_state_contains_details(): void
    {
        $exception = WorkflowException::invalidState('nonexistent', 'order_process');

        self::assertStringContainsString('nonexistent', $exception->getMessage());
        self::assertStringContainsString('order_process', $exception->getMessage());
    }

    #[Test]
    public function test_definition_not_found_contains_name(): void
    {
        $exception = WorkflowException::definitionNotFound('missing_workflow');

        self::assertStringContainsString('missing_workflow', $exception->getMessage());
    }

    #[Test]
    public function test_version_not_found_contains_id_and_version(): void
    {
        $exception = WorkflowException::versionNotFound('order_process', 99);

        self::assertStringContainsString('order_process', $exception->getMessage());
        self::assertStringContainsString('99', $exception->getMessage());
    }

    #[Test]
    public function test_invalid_definition_contains_name_and_reason(): void
    {
        $exception = WorkflowException::invalidDefinition('bad_workflow', 'no initial state');

        self::assertStringContainsString('bad_workflow', $exception->getMessage());
        self::assertStringContainsString('no initial state', $exception->getMessage());
    }

    #[Test]
    public function test_guard_blocked_contains_details(): void
    {
        $exception = WorkflowException::guardBlocked('approve', 'RoleGuard', 'Missing admin role');

        self::assertStringContainsString('approve', $exception->getMessage());
        self::assertStringContainsString('RoleGuard', $exception->getMessage());
        self::assertStringContainsString('Missing admin role', $exception->getMessage());
    }

    #[Test]
    public function test_concurrent_transition_contains_details(): void
    {
        $exception = WorkflowException::concurrentTransition('inst-42', 5, 6);

        self::assertStringContainsString('inst-42', $exception->getMessage());
        self::assertStringContainsString('5', $exception->getMessage());
        self::assertStringContainsString('6', $exception->getMessage());
    }

    #[Test]
    public function test_transition_from_final_state_contains_details(): void
    {
        $exception = WorkflowException::transitionFromFinalState('completed', 'order_process');

        self::assertStringContainsString('completed', $exception->getMessage());
        self::assertStringContainsString('order_process', $exception->getMessage());
        self::assertStringContainsString('final state', $exception->getMessage());
    }
}
