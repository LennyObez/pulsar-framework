<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Workflow;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Workflow\Definition\StateType;
use Pulsar\Workflow\Definition\WorkflowType;
use Pulsar\Workflow\Storage\ClassificationLevel;
use Pulsar\Workflow\Storage\WorkflowInstanceStatus;
use ValueError;

#[CoversNothing]
final class WorkflowEnumExtendedTest extends TestCase
{
    // ── StateType ──────────────────────────────────────────────────────

    #[Test]
    public function stateTypeHasThreeCases(): void
    {
        self::assertCount(3, StateType::cases());
    }

    #[Test]
    #[DataProvider('stateTypeProvider')]
    public function stateTypeBackedValues(StateType $type, string $expected): void
    {
        self::assertSame($expected, $type->value);
    }

    /**
     * @return iterable<string, array{StateType, string}>
     */
    public static function stateTypeProvider(): iterable
    {
        yield 'Initial' => [StateType::Initial, 'initial'];
        yield 'Intermediate' => [StateType::Intermediate, 'intermediate'];
        yield 'Final' => [StateType::Final, 'final'];
    }

    #[Test]
    public function stateTypeFromBackedValue(): void
    {
        self::assertSame(StateType::Initial, StateType::from('initial'));
        self::assertSame(StateType::Intermediate, StateType::from('intermediate'));
        self::assertSame(StateType::Final, StateType::from('final'));
    }

    #[Test]
    public function stateTypeFromInvalidValueThrows(): void
    {
        $this->expectException(ValueError::class);

        StateType::from('unknown');
    }

    // ── WorkflowType ───────────────────────────────────────────────────

    #[Test]
    public function workflowTypeHasTwoCases(): void
    {
        self::assertCount(2, WorkflowType::cases());
    }

    #[Test]
    #[DataProvider('workflowTypeProvider')]
    public function workflowTypeBackedValues(WorkflowType $type, string $expected): void
    {
        self::assertSame($expected, $type->value);
    }

    /**
     * @return iterable<string, array{WorkflowType, string}>
     */
    public static function workflowTypeProvider(): iterable
    {
        yield 'StateMachine' => [WorkflowType::StateMachine, 'state_machine'];
        yield 'Workflow' => [WorkflowType::Workflow, 'workflow'];
    }

    #[Test]
    public function workflowTypeFromBackedValue(): void
    {
        self::assertSame(WorkflowType::StateMachine, WorkflowType::from('state_machine'));
        self::assertSame(WorkflowType::Workflow, WorkflowType::from('workflow'));
    }

    // ── ClassificationLevel ────────────────────────────────────────────

    #[Test]
    public function classificationLevelHasFourCases(): void
    {
        self::assertCount(4, ClassificationLevel::cases());
    }

    #[Test]
    #[DataProvider('classificationLevelProvider')]
    public function classificationLevelBackedValues(ClassificationLevel $level, string $expected): void
    {
        self::assertSame($expected, $level->value);
    }

    /**
     * @return iterable<string, array{ClassificationLevel, string}>
     */
    public static function classificationLevelProvider(): iterable
    {
        yield 'Public' => [ClassificationLevel::Public, 'public'];
        yield 'Internal' => [ClassificationLevel::Internal, 'internal'];
        yield 'Restricted' => [ClassificationLevel::Restricted, 'restricted'];
        yield 'Pii' => [ClassificationLevel::Pii, 'pii'];
    }

    #[Test]
    public function classificationLevelIsAtOrBelowRespectsOrdering(): void
    {
        // Public is at or below everything
        self::assertTrue(ClassificationLevel::Public->isAtOrBelow(ClassificationLevel::Public));
        self::assertTrue(ClassificationLevel::Public->isAtOrBelow(ClassificationLevel::Internal));
        self::assertTrue(ClassificationLevel::Public->isAtOrBelow(ClassificationLevel::Restricted));
        self::assertTrue(ClassificationLevel::Public->isAtOrBelow(ClassificationLevel::Pii));

        // Internal is at or below Internal, Restricted, Pii
        self::assertFalse(ClassificationLevel::Internal->isAtOrBelow(ClassificationLevel::Public));
        self::assertTrue(ClassificationLevel::Internal->isAtOrBelow(ClassificationLevel::Internal));
        self::assertTrue(ClassificationLevel::Internal->isAtOrBelow(ClassificationLevel::Restricted));
        self::assertTrue(ClassificationLevel::Internal->isAtOrBelow(ClassificationLevel::Pii));

        // Restricted is at or below Restricted, Pii
        self::assertFalse(ClassificationLevel::Restricted->isAtOrBelow(ClassificationLevel::Public));
        self::assertFalse(ClassificationLevel::Restricted->isAtOrBelow(ClassificationLevel::Internal));
        self::assertTrue(ClassificationLevel::Restricted->isAtOrBelow(ClassificationLevel::Restricted));
        self::assertTrue(ClassificationLevel::Restricted->isAtOrBelow(ClassificationLevel::Pii));

        // Pii is only at or below Pii
        self::assertFalse(ClassificationLevel::Pii->isAtOrBelow(ClassificationLevel::Public));
        self::assertFalse(ClassificationLevel::Pii->isAtOrBelow(ClassificationLevel::Internal));
        self::assertFalse(ClassificationLevel::Pii->isAtOrBelow(ClassificationLevel::Restricted));
        self::assertTrue(ClassificationLevel::Pii->isAtOrBelow(ClassificationLevel::Pii));
    }

    // ── WorkflowInstanceStatus ────────────────────────────────────────

    #[Test]
    public function workflowInstanceStatusHasFourCases(): void
    {
        self::assertCount(4, WorkflowInstanceStatus::cases());
    }

    #[Test]
    #[DataProvider('instanceStatusProvider')]
    public function instanceStatusBackedValues(WorkflowInstanceStatus $status, string $expected): void
    {
        self::assertSame($expected, $status->value);
    }

    /**
     * @return iterable<string, array{WorkflowInstanceStatus, string}>
     */
    public static function instanceStatusProvider(): iterable
    {
        yield 'Active' => [WorkflowInstanceStatus::Active, 'active'];
        yield 'Completed' => [WorkflowInstanceStatus::Completed, 'completed'];
        yield 'Failed' => [WorkflowInstanceStatus::Failed, 'failed'];
        yield 'Compensating' => [WorkflowInstanceStatus::Compensating, 'compensating'];
    }

    #[Test]
    public function instanceStatusFromBackedValue(): void
    {
        self::assertSame(WorkflowInstanceStatus::Active, WorkflowInstanceStatus::from('active'));
        self::assertSame(WorkflowInstanceStatus::Failed, WorkflowInstanceStatus::from('failed'));
        self::assertSame(WorkflowInstanceStatus::Compensating, WorkflowInstanceStatus::from('compensating'));
    }

    #[Test]
    public function instanceStatusTryFromReturnsNullForInvalid(): void
    {
        self::assertNull(WorkflowInstanceStatus::tryFrom('cancelled'));
    }
}
