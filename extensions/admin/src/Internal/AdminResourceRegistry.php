<?php

declare(strict_types=1);

namespace Pulsar\Extension\Admin\Internal;

use Override;
use Pulsar\Api\Internal;
use Pulsar\Extension\Admin\Contracts\DataResourceInterface;
use Pulsar\Extension\Admin\Contracts\ResourceRegistryInterface;
use Pulsar\Extension\Admin\Exception\AdminException;
use Pulsar\Extension\Admin\Exception\ResourceNotFoundException;
use Pulsar\Extension\Admin\Internal\Adapter\IntrospectedResource;

/**
 * In-memory resource registry.
 */
#[Internal]
final class AdminResourceRegistry implements ResourceRegistryInterface
{
    /** @var array<string, DataResourceInterface> */
    private array $resources = [];

    #[Override]
    public function register(DataResourceInterface $resource): void
    {
        $name = $resource->name();
        if (isset($this->resources[$name])) {
            // Allow explicit typed resources to replace auto-introspected ones.
            // Extensions register richer resources (custom labels, operations,
            // field definitions) that should take precedence over generic
            // table-derived resources created by ORM introspection.
            if ($this->resources[$name] instanceof IntrospectedResource && !$resource instanceof IntrospectedResource) {
                $this->resources[$name] = $resource;

                return;
            }

            throw AdminException::resourceAlreadyRegistered($name);
        }
        $this->resources[$name] = $resource;
    }

    #[Override]
    public function get(string $name): DataResourceInterface
    {
        if (!isset($this->resources[$name])) {
            throw ResourceNotFoundException::resource($name);
        }
        return $this->resources[$name];
    }

    #[Override]
    public function has(string $name): bool
    {
        return isset($this->resources[$name]);
    }

    #[Override]
    public function all(): array
    {
        return $this->resources;
    }
}
