<?php

declare(strict_types=1);

namespace Pulsar\Workflow\Visualization;

use Pulsar\Api\Api;
use Pulsar\Workflow\Definition\WorkflowDefinition;
use Pulsar\Workflow\Storage\WorkflowInstance;

/**
 * Generates DOT (Graphviz) format representations of workflow definitions.
 *
 * The output is a valid DOT language string that can be rendered by
 * Graphviz tools (dot, neato, etc.) into SVG, PNG, or PDF.
 */
#[Api(since: '1.0.0')]
interface DotGraphExporterInterface
{
    /**
     * Export a workflow definition as a DOT graph.
     */
    public function export(WorkflowDefinition $definition): string;

    /**
     * Export a workflow definition with the current state(s) highlighted.
     *
     * The active state(s) from the instance are visually distinguished
     * in the generated graph (e.g., bold border, different fill color).
     */
    public function exportWithInstance(WorkflowDefinition $definition, WorkflowInstance $instance): string;
}
