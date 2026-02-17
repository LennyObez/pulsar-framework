<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Workflow;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Workflow\Definition\StateType;
use Pulsar\Workflow\Definition\WorkflowType;
use Pulsar\Workflow\Storage\ClassificationLevel;
use Pulsar\Workflow\Storage\WorkflowInstanceStatus;

#[CoversClass(StateType::class)]
#[CoversClass(WorkflowType::class)]
#[CoversClass(ClassificationLevel::class)]
#[CoversClass(WorkflowInstanceStatus::class)]
final class WorkflowEnumTest extends TestCase
{
    // ── StateType ───────────────────────────────────────────────────────

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

    // ── WorkflowType ────────────────────────────────────────────────────

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

    // ── ClassificationLevel ─────────────────────────────────────────────

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
    public function isAtOrBelowRespectsOrdinalOrder(): void
    {
        self::assertTrue(ClassificationLevel::Public->isAtOrBelow(ClassificationLevel::Pii));
        self::assertTrue(ClassificationLevel::Public->isAtOrBelow(ClassificationLevel::Public));
        self::assertTrue(ClassificationLevel::Internal->isAtOrBelow(ClassificationLevel::Restricted));
        self::assertFalse(ClassificationLevel::Pii->isAtOrBelow(ClassificationLevel::Restricted));
        self::assertFalse(ClassificationLevel::Restricted->isAtOrBelow(ClassificationLevel::Internal));
    }

    #[Test]
    public function isAtOrBelowSameLevel(): void
    {
        foreach (ClassificationLevel::cases() as $level) {
            self::assertTrue($level->isAtOrBelow($level));
        }
    }

    // ── WorkflowInstanceStatus ──────────────────────────────────────────

    #[Test]
    public function workflowInstanceStatusHasFourCases(): void
    {
        self::assertCount(4, WorkflowInstanceStatus::cases());
    }

    #[Test]
    #[DataProvider('instanceStatusProvider')]
    public function workflowInstanceStatusBackedValues(WorkflowInstanceStatus $status, string $expected): void
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
    public function fromBackedValues(): void
    {
        self::assertSame(StateType::Final, StateType::from('final'));
        self::assertSame(WorkflowType::StateMachine, WorkflowType::from('state_machine'));
        self::assertSame(ClassificationLevel::Pii, ClassificationLevel::from('pii'));
        self::assertSame(WorkflowInstanceStatus::Compensating, WorkflowInstanceStatus::from('compensating'));
    }
}
