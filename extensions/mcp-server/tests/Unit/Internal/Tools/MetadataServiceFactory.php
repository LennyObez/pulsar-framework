<?php

declare(strict_types=1);

namespace Pulsar\Extension\McpServer\Tests\Unit\Internal\Tools;

use Pulsar\Introspection\ProjectMetadataService;
use Pulsar\Introspection\ProjectMetadataSnapshot;
use ReflectionClass;
use ReflectionProperty;

/**
 * Test helper: builds a ProjectMetadataService pre-seeded with a given snapshot.
 *
 * Uses reflection to create an uninitialized instance and set only the
 * cached snapshot field, avoiding the complex constructor dependencies.
 */
final class MetadataServiceFactory
{
    public static function withSnapshot(ProjectMetadataSnapshot $snapshot): ProjectMetadataService
    {
        $ref = new ReflectionClass(ProjectMetadataService::class);
        $service = $ref->newInstanceWithoutConstructor();

        $prop = new ReflectionProperty(ProjectMetadataService::class, 'cached');
        $prop->setValue($service, $snapshot);

        return $service;
    }
}
