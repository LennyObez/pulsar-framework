<?php

declare(strict_types=1);

namespace Pulsar\Workflow\Definition\Versioning;

use DateTimeImmutable;
use Pulsar\Api\Api;
use Pulsar\Workflow\Definition\WorkflowDefinition;

/**
 * An immutable snapshot of a workflow definition at a specific version.
 *
 * Every time a workflow definition is published, a new DefinitionVersion is
 * recorded. Existing in-flight instances continue on the version they started
 * with; new instances use the latest published version.
 *
 * Old versions are retained and never deleted — this enables auditability
 * and replay of historical workflow behavior.
 */
#[Api(since: '1.0.0')]
final readonly class DefinitionVersion
{
    public function __construct(
        public string $definitionId,
        public int $version,
        public WorkflowDefinition $definition,
        public DateTimeImmutable $createdAt,
    ) {}
}
