<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Navigation;

use Pulsar\Api\Api;

/**
 * A menu item with its locale-specific label resolved from translations.
 *
 * This is a read-only projection used by templates. The label and titleAttr
 * come from the MenuItemTranslation for the requested locale, with fallback
 * to the first available translation.
 */
#[Api(since: '1.0.0')]
final readonly class MenuItemResolved
{
    public function __construct(
        public string $id,
        public string $menuId,
        public ?string $parentId,
        public ?string $contentId,
        public ?string $url,
        public string $label,
        public ?string $titleAttr,
        public LinkTarget $target,
        public ?string $cssClass,
        public ?string $icon,
        public int $sortOrder,
        public bool $visible,
        public ?string $contentPath = null,
    ) {}
}
