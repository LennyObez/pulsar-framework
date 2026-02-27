<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Navigation;

use Pulsar\Api\Api;

/**
 * A single item within a navigation menu.
 *
 * Menu items form a tree via parent_id and can link to either
 * internal content (via content_id) or external URLs.
 *
 * @psalm-api Public DTO returned from MenuRepositoryInterface; consumed by
 *            navigation rendering.
 */
#[Api(since: '1.0.0')]
final readonly class MenuItem
{
    /**
     * @param string $id UUIDv7
     * @param string $menuId UUIDv7 FK menus
     * @param string|null $parentId UUIDv7 self-referential tree
     * @param string|null $contentId UUIDv7 FK content (auto-resolves URL from slug)
     * @param string|null $url External URL (mutually exclusive with contentId)
     * @param LinkTarget $target Link target attribute
     * @param string|null $cssClass Optional CSS class for styling
     * @param string|null $icon Optional icon identifier
     * @param int $sortOrder Position among siblings
     * @param bool $visible Whether the item is rendered
     * @param string|null $importId Stable import identifier for idempotent imports
     */
    public function __construct(
        public string $id,
        public string $menuId,
        public ?string $parentId,
        public ?string $contentId,
        public ?string $url,
        public LinkTarget $target,
        public ?string $cssClass,
        public ?string $icon,
        public int $sortOrder,
        public bool $visible,
        public ?string $importId = null,
    ) {}
}
