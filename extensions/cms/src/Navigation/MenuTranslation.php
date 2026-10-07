<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Navigation;

use Pulsar\Api\Api;

/**
 * Per-locale translation for a navigation menu.
 *
 * @psalm-api Public DTO returned from MenuRepositoryInterface; consumed
 *            by navigation rendering and admin editor.
 * @api
 */
#[Api(since: '1.0.0')]
final readonly class MenuTranslation
{
    /**
     * @param string $menuId UUIDv7 FK menus
     * @param string $locale BCP 47 locale code
     * @param string $name Display name for the menu
     */
    public function __construct(
        public string $menuId,
        public string $locale,
        public string $name,
    ) {}
}
