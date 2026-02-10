<?php

declare(strict_types=1);

namespace Pulsar\Workflow\Definition\Versioning;

use Pulsar\Api\Api;
use Pulsar\Workflow\Exception\WorkflowException;

/**
 * Port for migrating in-flight workflow instance state between definition versions.
 *
 * Migration is opt-in and never automatic. When a definition change must apply
 * to running instances, an explicit migrator transforms the instance state from
 * one definition version to another.
 *
 * Implementations are responsible for mapping old state names/shapes to the new
 * definition's expectations. Failed migration must throw — partial state
 * corruption is never acceptable.
 */
#[Api(since: '1.0.0')]
interface DefinitionMigratorInterface
{
    /**
     * Migrate instance state from one definition version to another.
     *
     * @param array<string, mixed> $instanceState The current instance state to transform
     * @param DefinitionVersion    $fromVersion   The version the instance was created with
     * @param DefinitionVersion    $toVersion     The target version to migrate to
     *
     * @return array<string, mixed> The migrated instance state
     *
     * @throws WorkflowException If migration fails (state cannot be transformed)
     */
    public function migrate(
        array $instanceState,
        DefinitionVersion $fromVersion,
        DefinitionVersion $toVersion,
    ): array;
}
