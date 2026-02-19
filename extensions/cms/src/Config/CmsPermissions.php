<?php

declare(strict_types=1);

namespace Pulsar\Extension\Cms\Config;

use Pulsar\Api\Internal;
use Pulsar\Auth\Authorization\Permission;
use Pulsar\Auth\Authorization\Role;
use Pulsar\Auth\Authorization\RoleRegistryInterface;

/**
 * Defines all CMS roles and permissions, then registers them
 * with Pulsar's RoleRegistryInterface during extension boot.
 */
#[Internal(reason: 'CMS permission wiring — not a public API surface')]
final class CmsPermissions
{
    /** @var list<string> */
    private const array VIEWER_PERMISSIONS = [];

    /** @var list<string> */
    private const array CONTRIBUTOR_PERMISSIONS = [
        'cms.dashboard.view',
        'cms.content.view',
        'cms.content.create',
        'cms.content.edit_own',
        'cms.content.submit_review',
        'cms.taxonomy.view',
        'cms.menus.view',
        'cms.media.view',
        'cms.comments.view',
    ];

    /** @var list<string> */
    private const array REVIEWER_PERMISSIONS = [
        'cms.content.approve',
    ];

    /** @var list<string> */
    private const array EDITOR_PERMISSIONS = [
        'cms.content.edit',
        'cms.content.publish',
        'cms.content.archive',
        'cms.content.restore',
        'cms.content.delete',
        'cms.content.force_unlock',
        'cms.taxonomy.manage',
        'cms.menus.manage',
        'cms.comments.moderate',
    ];

    /** @var list<string> */
    private const array MEDIA_MANAGER_PERMISSIONS = [
        'cms.media.upload',
        'cms.media.delete',
    ];

    /** @var list<string> */
    private const array SEO_MANAGER_PERMISSIONS = [
        'cms.seo.view',
        'cms.seo.manage',
    ];

    /** @var list<string> */
    private const array SHOP_MANAGER_PERMISSIONS = [
        'cms.orders.view',
        'cms.orders.manage',
        'cms.orders.refund',
        'cms.products.view',
        'cms.products.manage',
    ];

    /** @var list<string> */
    private const array ADMIN_PERMISSIONS = [
        'cms.content.manage_fields',
        'cms.themes.view',
        'cms.themes.install',
        'cms.themes.manage',
        'cms.themes.delete',
        'cms.plugins.view',
        'cms.plugins.install',
        'cms.plugins.manage',
        'cms.plugins.delete',
        'cms.settings.view',
        'cms.settings.manage',
        'cms.users.view',
        'cms.users.manage',
        'cms.tools.export',
        'cms.tools.import',
        'cms.tools.backup',
        'cms.tools.restore',
        'cms.livecss.view',
        'cms.livecss.edit',
    ];

    /**
     * Register all CMS roles and their permissions with the role registry.
     */
    public static function register(RoleRegistryInterface $registry): void
    {
        $registry->register(self::buildRole('cms.viewer', self::VIEWER_PERMISSIONS));

        $registry->register(self::buildRole('cms.contributor', [
            ...self::VIEWER_PERMISSIONS,
            ...self::CONTRIBUTOR_PERMISSIONS,
        ]));

        $registry->register(self::buildRole('cms.reviewer', [
            ...self::VIEWER_PERMISSIONS,
            ...self::CONTRIBUTOR_PERMISSIONS,
            ...self::REVIEWER_PERMISSIONS,
        ]));

        $registry->register(self::buildRole('cms.editor', [
            ...self::VIEWER_PERMISSIONS,
            ...self::CONTRIBUTOR_PERMISSIONS,
            ...self::REVIEWER_PERMISSIONS,
            ...self::EDITOR_PERMISSIONS,
        ]));

        $registry->register(self::buildRole('cms.media_manager', [
            ...self::VIEWER_PERMISSIONS,
            ...self::MEDIA_MANAGER_PERMISSIONS,
        ]));

        $registry->register(self::buildRole('cms.seo_manager', [
            ...self::VIEWER_PERMISSIONS,
            ...self::SEO_MANAGER_PERMISSIONS,
        ]));

        $registry->register(self::buildRole('cms.shop_manager', [
            ...self::VIEWER_PERMISSIONS,
            ...self::SHOP_MANAGER_PERMISSIONS,
        ]));

        $registry->register(self::buildRole('cms.admin', [
            ...self::VIEWER_PERMISSIONS,
            ...self::CONTRIBUTOR_PERMISSIONS,
            ...self::REVIEWER_PERMISSIONS,
            ...self::EDITOR_PERMISSIONS,
            ...self::MEDIA_MANAGER_PERMISSIONS,
            ...self::SEO_MANAGER_PERMISSIONS,
            ...self::SHOP_MANAGER_PERMISSIONS,
            ...self::ADMIN_PERMISSIONS,
        ]));
    }

    /**
     * @param list<string> $permissions
     */
    private static function buildRole(string $name, array $permissions): Role
    {
        return new Role(
            name: $name,
            permissions: array_map(
                static fn(string $p): Permission => new Permission($p),
                $permissions,
            ),
        );
    }
}
