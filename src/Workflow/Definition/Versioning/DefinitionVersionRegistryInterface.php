<?php

declare(strict_types=1);

namespace Pulsar\Workflow\Definition\Versioning;

use Pulsar\Api\Api;
use Pulsar\Workflow\Exception\WorkflowException;

/**
 * Port for storing and retrieving published workflow definition versions.
 *
 * Implementations may back this with a database, file system, or in-memory
 * store. Old versions are retained and never deleted for auditability.
 */
#[Api(since: '1.0.0')]
interface DefinitionVersionRegistryInterface
{
    /**
     * Register a new version of a workflow definition.
     *
     * The version number is determined by the implementation (typically
     * auto-incremented from the latest version for the given definition ID).
     */
    public function register(DefinitionVersion $version): void;

    /**
     * Get the latest published version for a definition.
     *
     * @throws WorkflowException If no versions exist for the given definition ID
     */
    public function latest(string $definitionId): DefinitionVersion;

    /**
     * Get a specific version of a definition.
     *
     * @throws WorkflowException If the requested version does not exist
     */
    public function get(string $definitionId, int $version): DefinitionVersion;

    /**
     * Get all published versions for a definition, ordered by version number ascending.
     *
     * @return list<DefinitionVersion>
     */
    public function all(string $definitionId): array;
}
