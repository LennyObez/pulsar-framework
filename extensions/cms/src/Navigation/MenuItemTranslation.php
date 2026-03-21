<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Navigation;

use Pulsar\Api\Api;

/**
 * Per-locale translation for a menu item.
 *
 * @psalm-api Public DTO returned from MenuRepositoryInterface; consumed
 *            by navigation rendering and admin editor.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class MenuItemTranslation
{
    /**
     * @param string $menuItemId UUIDv7 FK menu_items
     * @param string $locale BCP 47 locale code
     * @param string $label Display label for the menu item
     * @param string|null $titleAttr HTML title attribute for accessibility
     */
    public function __construct(
        public string $menuItemId,
        public string $locale,
        public string $label,
        public ?string $titleAttr,
    ) {}
}
