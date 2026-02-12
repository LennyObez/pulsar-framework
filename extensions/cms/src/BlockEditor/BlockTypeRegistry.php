<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\BlockEditor;

use Pulsar\Api\Api;

/**
 * Registry of available block types for the CMS block editor.
 *
 * Block types are registered during the extension boot phase. Plugins and
 * themes may register additional custom block types via the container.
 */
#[Api(since: '1.0.0')]
final class BlockTypeRegistry
{
    /** @var array<string, BlockTypeInterface> */
    private array $blocks = [];

    /**
     * Register a block type. Overwrites any previously registered type
     * with the same identifier.
     */
    public function register(BlockTypeInterface $blockType): void
    {
        $this->blocks[$blockType->type()] = $blockType;
    }

    /**
     * Retrieve a block type by its identifier.
     */
    public function get(string $type): ?BlockTypeInterface
    {
        return $this->blocks[$type] ?? null;
    }

    /**
     * Check whether a block type is registered.
     */
    public function has(string $type): bool
    {
        return isset($this->blocks[$type]);
    }

    /**
     * Return all registered block types keyed by type identifier.
     *
     * @return array<string, BlockTypeInterface>
     */
    public function all(): array
    {
        return $this->blocks;
    }
}
