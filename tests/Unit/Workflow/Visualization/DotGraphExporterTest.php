<?php

declare(strict_types=1);

namespace Pulsar\Tests\Unit\Workflow\Visualization;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Pulsar\Workflow\Definition\DefinitionBuilder;
use Pulsar\Workflow\Definition\WorkflowType;
use Pulsar\Workflow\Storage\ClassifiedContext;
use Pulsar\Workflow\Storage\WorkflowInstance;
use Pulsar\Workflow\Storage\WorkflowInstanceStatus;
use Pulsar\Workflow\Visualization\DotGraphExporter;

#[CoversClass(DotGraphExporter::class)]
final class DotGraphExporterTest extends TestCase
{
    private DotGraphExporter $exporter;

    protected function setUp(): void
    {
        $this->exporter = new DotGraphExporter();
    }

    #[Test]
    public function test_export_produces_valid_dot_output(): void
    {
        $definition = DefinitionBuilder::create('test')
            ->initialState('start')
            ->state('middle')
            ->finalState('end')
            ->transition('begin', 'start', 'middle')
            ->transition('finish', 'middle', 'end')
            ->build();

        $dot = $this->exporter->export($definition);

        self::assertStringContainsString('digraph "test"', $dot);
        self::assertStringContainsString('{', $dot);
        self::assertStringContainsString('}', $dot);
    }

    #[Test]
    public function test_export_includes_all_states(): void
    {
        $definition = DefinitionBuilder::create('test')
            ->initialState('start')
            ->state('middle')
            ->finalState('end')
            ->transition('step1', 'start', 'middle')
            ->transition('step2', 'middle', 'end')
            ->build();

        $dot = $this->exporter->export($definition);

        self::assertStringContainsString('"start"', $dot);
        self::assertStringContainsString('"middle"', $dot);
        self::assertStringContainsString('"end"', $dot);
    }

    #[Test]
    public function test_export_includes_transition_edges(): void
    {
        $definition = DefinitionBuilder::create('test')
            ->initialState('start')
            ->finalState('end')
            ->transition('go', 'start', 'end')
            ->build();

        $dot = $this->exporter->export($definition);

        self::assertStringContainsString('"start" -> "end"', $dot);
        self::assertStringContainsString('go', $dot);
    }

    #[Test]
    public function test_export_includes_entry_point_marker(): void
    {
        $definition = DefinitionBuilder::create('test')
            ->initialState('start')
            ->finalState('end')
            ->transition('go', 'start', 'end')
            ->build();

        $dot = $this->exporter->export($definition);

        self::assertStringContainsString('__start__', $dot);
        self::assertStringContainsString('shape=point', $dot);
    }

    #[Test]
    public function test_export_includes_entry_point_to_initial_state_edge(): void
    {
        $definition = DefinitionBuilder::create('test')
            ->initialState('begin')
            ->finalState('end')
            ->transition('go', 'begin', 'end')
            ->build();

        $dot = $this->exporter->export($definition);

        self::assertStringContainsString('__start__ -> "begin"', $dot);
    }

    #[Test]
    public function test_initial_state_uses_doublecircle_shape(): void
    {
        $definition = DefinitionBuilder::create('test')
            ->initialState('start')
            ->finalState('end')
            ->transition('go', 'start', 'end')
            ->build();

        $dot = $this->exporter->export($definition);

        self::assertMatchesRegularExpression('/"start"\s+\[.*doublecircle/', $dot);
    }

    #[Test]
    public function test_final_state_uses_doubleoctagon_shape(): void
    {
        $definition = DefinitionBuilder::create('test')
            ->initialState('start')
            ->finalState('end')
            ->transition('go', 'start', 'end')
            ->build();

        $dot = $this->exporter->export($definition);

        self::assertMatchesRegularExpression('/"end"\s+\[.*doubleoctagon/', $dot);
    }

    #[Test]
    public function test_intermediate_state_uses_box_shape(): void
    {
        $definition = DefinitionBuilder::create('test')
            ->initialState('start')
            ->state('middle')
            ->finalState('end')
            ->transition('step1', 'start', 'middle')
            ->transition('step2', 'middle', 'end')
            ->build();

        $dot = $this->exporter->export($definition);

        self::assertMatchesRegularExpression('/"middle"\s+\[.*shape="box"/', $dot);
    }

    #[Test]
    public function test_export_with_instance_highlights_active_state(): void
    {
        $definition = DefinitionBuilder::create('test')
            ->initialState('start')
            ->state('middle')
            ->finalState('end')
            ->transition('step1', 'start', 'middle')
            ->transition('step2', 'middle', 'end')
            ->build();

        $instance = new WorkflowInstance(
            id: 'inst-1',
            definitionId: 'test',
            definitionVersion: 1,
            currentState: 'middle',
            context: new ClassifiedContext(),
            version: 2,
            status: WorkflowInstanceStatus::Active,
            startedAt: new DateTimeImmutable(),
            completedAt: null,
            startedBy: 'user-1',
        );

        $dot = $this->exporter->exportWithInstance($definition, $instance);

        self::assertStringContainsString('#4CAF50', $dot);
        self::assertMatchesRegularExpression('/"middle"\s+\[.*filled,bold/', $dot);
    }

    #[Test]
    public function test_export_without_instance_does_not_highlight(): void
    {
        $definition = DefinitionBuilder::create('test')
            ->initialState('start')
            ->finalState('end')
            ->transition('go', 'start', 'end')
            ->build();

        $dot = $this->exporter->export($definition);

        self::assertStringNotContainsString('#4CAF50', $dot);
        self::assertStringNotContainsString('filled,bold', $dot);
    }

    #[Test]
    public function test_export_includes_guard_annotations_on_edges(): void
    {
        $definition = DefinitionBuilder::create('test')
            ->initialState('start')
            ->finalState('end')
            ->transition('go', 'start', 'end', guards: ['Pulsar\\Workflow\\Guard\\RoleGuard'])
            ->build();

        $dot = $this->exporter->export($definition);

        self::assertStringContainsString('RoleGuard', $dot);
    }

    #[Test]
    public function test_export_uses_metadata_label_when_available(): void
    {
        $definition = DefinitionBuilder::create('test')
            ->initialState('start', ['label' => 'Start Here'])
            ->finalState('end', ['label' => 'Finished'])
            ->transition('go', 'start', 'end')
            ->build();

        $dot = $this->exporter->export($definition);

        self::assertStringContainsString('Start Here', $dot);
        self::assertStringContainsString('Finished', $dot);
    }

    #[Test]
    public function test_export_includes_rankdir_lr(): void
    {
        $definition = DefinitionBuilder::create('test')
            ->initialState('start')
            ->finalState('end')
            ->transition('go', 'start', 'end')
            ->build();

        $dot = $this->exporter->export($definition);

        self::assertStringContainsString('rankdir=LR', $dot);
    }

    #[Test]
    public function test_export_multi_from_transition_creates_multiple_edges(): void
    {
        $definition = DefinitionBuilder::create('parallel')
            ->type(WorkflowType::Workflow)
            ->initialState('start')
            ->state('branch_a')
            ->state('branch_b')
            ->finalState('joined')
            ->transition('fork_a', 'start', 'branch_a')
            ->transition('fork_b', 'start', 'branch_b')
            ->joinTransition('merge', ['branch_a', 'branch_b'], 'joined')
            ->build();

        $dot = $this->exporter->export($definition);

        self::assertStringContainsString('"branch_a" -> "joined"', $dot);
        self::assertStringContainsString('"branch_b" -> "joined"', $dot);
    }
}
