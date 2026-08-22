<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\FieldRegistry;

use Pulsar\Api\Api;

/**
 * Registry for custom content type definitions.
 *
 * Plugins and extensions register content types during boot,
 * and the admin UI auto-generates forms based on field definitions.
 *
 * @psalm-api Public binding contract; implemented by ContentTypeRegistry and
 *            consumed by admin UI generators.
 * @api
 */
#[Api(since: '1.0.0')]
interface ContentTypeRegistryInterface
{
    /**
     * Register a content type definition.
     */
    public function register(ContentTypeDefinition $definition): void;

    /**
     * Retrieve a content type definition by its machine type.
     */
    public function get(string $type): ?ContentTypeDefinition;

    /**
     * Retrieve all registered content type definitions.
     *
     * @return array<string, ContentTypeDefinition> Keyed by type
     */
    public function all(): array;
}
