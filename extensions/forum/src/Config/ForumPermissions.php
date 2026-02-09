<?php

declare(strict_types=1);

namespace Pulsar\Extension\Forum\Config;

use Pulsar\Api\Internal;
use Pulsar\Auth\Authorization\Permission;
use Pulsar\Auth\Authorization\Role;
use Pulsar\Auth\Authorization\RoleRegistryInterface;

/**
 * Defines all forum roles and permissions, then registers them
 * with Pulsar's RoleRegistryInterface during extension boot.
 */
#[Internal(reason: 'Forum permission wiring — not a public API surface')]
final class ForumPermissions
{
    /** @var list<string> */
    private const array VIEWER_PERMISSIONS = [
        'forum.threads.view',
        'forum.posts.view',
        'forum.categories.view',
        'forum.tags.view',
        'forum.profiles.view',
    ];

    /** @var list<string> */
    private const array MEMBER_PERMISSIONS = [
        'forum.threads.create',
        'forum.threads.edit_own',
        'forum.posts.create',
        'forum.posts.edit_own',
        'forum.votes.cast',
        'forum.reports.submit',
        'forum.subscriptions.manage',
        'forum.profiles.edit_own',
    ];

    /** @var list<string> */
    private const array MODERATOR_PERMISSIONS = [
        'forum.threads.edit',
        'forum.threads.delete',
        'forum.threads.lock',
        'forum.threads.unlock',
        'forum.threads.pin',
        'forum.threads.unpin',
        'forum.threads.move',
        'forum.posts.edit',
        'forum.posts.delete',
        'forum.posts.mark_solution',
        'forum.reports.review',
        'forum.reports.resolve',
        'forum.users.ban',
        'forum.users.unban',
    ];

    /** @var list<string> */
    private const array ADMIN_PERMISSIONS = [
        'forum.categories.create',
        'forum.categories.edit',
        'forum.categories.delete',
        'forum.tags.create',
        'forum.tags.edit',
        'forum.tags.delete',
        'forum.badges.manage',
        'forum.settings.view',
        'forum.settings.manage',
        'forum.dashboard.view',
        'forum.users.manage',
    ];

    /**
     * Register all forum roles and their permissions with the role registry.
     */
    public static function register(RoleRegistryInterface $registry): void
    {
        $registry->register(self::buildRole('forum.viewer', self::VIEWER_PERMISSIONS));

        $registry->register(self::buildRole('forum.member', [
            ...self::VIEWER_PERMISSIONS,
            ...self::MEMBER_PERMISSIONS,
        ]));

        $registry->register(self::buildRole('forum.moderator', [
            ...self::VIEWER_PERMISSIONS,
            ...self::MEMBER_PERMISSIONS,
            ...self::MODERATOR_PERMISSIONS,
        ]));

        $registry->register(self::buildRole('forum.admin', [
            ...self::VIEWER_PERMISSIONS,
            ...self::MEMBER_PERMISSIONS,
            ...self::MODERATOR_PERMISSIONS,
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
