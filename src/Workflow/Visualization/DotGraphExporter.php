<?php

declare(strict_types=1);

namespace Pulsar\Workflow\Visualization;

use Override;
use Pulsar\Api\Api;
use Pulsar\Workflow\Definition\StateDefinition;
use Pulsar\Workflow\Definition\StateType;
use Pulsar\Workflow\Definition\TransitionDefinition;
use Pulsar\Workflow\Definition\WorkflowDefinition;
use Pulsar\Workflow\Storage\WorkflowInstance;

use function array_find;
use function implode;
use function is_string;
use function sprintf;
use function str_replace;

/**
 * Generates DOT (Graphviz) format graphs from workflow definitions.
 *
 * Node styles vary by state type:
 * - Initial: double circle (point shape for entry marker)
 * - Intermediate: rounded rectangle (box with rounded corners)
 * - Final: double octagon
 *
 * When an instance is provided, the current state node receives a
 * highlighted fill to indicate the active position in the workflow.
 * @api
 */
#[Api(since: '1.0.0')]
final class DotGraphExporter implements DotGraphExporterInterface
{
    #[Override]
    public function export(WorkflowDefinition $definition): string
    {
        return $this->buildDot($definition, null);
    }

    #[Override]
    public function exportWithInstance(WorkflowDefinition $definition, WorkflowInstance $instance): string
    {
        return $this->buildDot($definition, $instance->currentState);
    }

    private function buildDot(WorkflowDefinition $definition, ?string $activeState): string
    {
        $lines = [];
        $lines[] = sprintf('digraph "%s" {', $this->escape($definition->name));
        $lines[] = '    rankdir=LR;';
        $lines[] = '    node [fontname="Helvetica" fontsize=10];';
        $lines[] = '    edge [fontname="Helvetica" fontsize=9];';
        $lines[] = '';

        // Entry point marker
        $lines[] = '    __start__ [shape=point width=0.2 label=""];';

        foreach ($definition->states as $state) {
            $lines[] = '    ' . $this->renderState($state, $activeState);
        }

        $lines[] = '';

        // Edge from entry point to initial state
        $initialState = $this->findInitialState($definition);

        if ($initialState !== null) {
            $lines[] = sprintf('    __start__ -> "%s";', $this->escape($initialState->name));
        }

        foreach ($definition->transitions as $transition) {
            foreach ($this->renderTransitionEdges($transition) as $edge) {
                $lines[] = '    ' . $edge;
            }
        }

        $lines[] = '}';

        return implode("\n", $lines) . "\n";
    }

    private function renderState(StateDefinition $state, ?string $activeState): string
    {
        $attrs = $this->stateAttributes($state);

        if ($activeState !== null && $state->name === $activeState) {
            $attrs['style'] = 'filled,bold';
            $attrs['fillcolor'] = '#4CAF50';
            $attrs['fontcolor'] = 'white';
        }

        $metadataLabel = $state->metadata['label'] ?? null;
        $attrs['label'] = is_string($metadataLabel) ? $metadataLabel : $state->name;

        return sprintf('"%s" [%s];', $this->escape($state->name), $this->formatAttributes($attrs));
    }

    /**
     * @return array<string, string>
     */
    private function stateAttributes(StateDefinition $state): array
    {
        return match ($state->type) {
            StateType::Initial => [
                'shape' => 'doublecircle',
                'style' => 'filled',
                'fillcolor' => '#E3F2FD',
            ],
            StateType::Final => [
                'shape' => 'doubleoctagon',
                'style' => 'filled',
                'fillcolor' => '#F3E5F5',
            ],
            StateType::Intermediate => [
                'shape' => 'box',
                'style' => 'rounded,filled',
                'fillcolor' => '#FAFAFA',
            ],
        };
    }

    /**
     * @return list<string>
     */
    private function renderTransitionEdges(TransitionDefinition $transition): array
    {
        $edges = [];
        $label = $transition->name;

        if ($transition->guards !== []) {
            $guardNames = [];

            foreach ($transition->guards as $guardClass) {
                $parts = explode('\\', $guardClass);
                $guardNames[] = end($parts);
            }

            $label .= sprintf('\n[%s]', implode(', ', $guardNames));
        }

        foreach ($transition->froms as $from) {
            $edges[] = sprintf(
                '"%s" -> "%s" [label="%s"];',
                $this->escape($from),
                $this->escape($transition->to),
                $this->escape($label),
            );
        }

        return $edges;
    }

    private function findInitialState(WorkflowDefinition $definition): ?StateDefinition
    {
        /** @var StateDefinition|null $found */
        $found = array_find($definition->states, static fn(StateDefinition $state): bool => $state->isInitial());

        return $found;
    }

    /**
     * @param array<string, string> $attrs
     */
    private function formatAttributes(array $attrs): string
    {
        $parts = [];

        foreach ($attrs as $key => $value) {
            $parts[] = sprintf('%s="%s"', $key, $this->escape($value));
        }

        return implode(' ', $parts);
    }

    /**
     * Escape a string for use in DOT double-quoted contexts.
     *
     * DOT format requires escaping backslashes and double quotes only.
     * Newlines are preserved as \n for Graphviz label line breaks.
     */
    private function escape(string $value): string
    {
        return str_replace(
            ['\\', '"'],
            ['\\\\', '\\"'],
            $value,
        );
    }
}
