<?php

declare(strict_types=1);

namespace Pulsar\Extension\Orm\Contracts;

use Pulsar\Api\Api;
use Pulsar\Extension\Orm\Domain\EntityMetadata;
use Pulsar\Extension\Orm\Exception\MappingException;

/**
 * Registry for entity metadata lookups.
 * @api
 */
#[Api(since: '1.0.0')]
interface MetadataRegistryInterface
{
    /**
     * Get metadata for the given entity class.
     *
     * @param class-string $entityClass
     * @throws MappingException
     */
    public function get(string $entityClass): EntityMetadata;

    /**
     * Check if metadata can be resolved for the given entity class.
     *
     * @param class-string $entityClass
     */
    public function has(string $entityClass): bool;
}
